"""Plan historical timing recovery from existing reports; never change source data."""
import argparse
import csv
import hashlib
import json
from collections import Counter, defaultdict
from datetime import datetime, timezone
from pathlib import Path
from zoneinfo import ZoneInfo

from discovery_order import assess_order
from season_gap_reconciliation import reconcile, unique_boundaries

VERSION = 'legacy-timing-recovery-plan-1.1.0'
SEASON_SECONDS = 6720


def iso(epoch, zone='UTC'):
    return datetime.fromtimestamp(epoch, timezone.utc).astimezone(ZoneInfo(zone)).isoformat() if epoch is not None else None


def segment_status(error):
    if error == 0:
        return 'cadence_agreement'
    if error > 0 and error % SEASON_SECONDS == 0:
        return 'extra_whole_cadence_slots'
    return 'longer_than_listed_cadence' if error > 0 else 'shorter_than_listed_cadence'


def build(timing, discovery):
    if timing.get('version') != 'historical-timing-audit-2.0.0' or discovery.get('version') != 'legacy-discovery-evidence-1.0.0':
        raise ValueError('Use version-2 timing and version-1 discovery reports.')
    if discovery.get('read_failures'):
        raise ValueError('Resolve discovery report read failures before planning recovery.')
    rows, identities, anchors = {}, defaultdict(set), {}
    for source in timing['seasons']:
        league, season = source['competition'], source['season_id']
        key = (league, season)
        if key in rows:
            raise ValueError('Duplicate season identity in timing report.')
        t = source.get('kiotrad_timing', {})
        at = t.get('day1_utc_epoch')
        conflict = bool(t.get('conflicting_days')) or t.get('status') == 'conflicting_timing'
        verified = not conflict and t.get('status') == 'verified_anchor_available' and type(at) is int and at > 0
        if verified:
            anchors[key] = at
        identities[season].add(league)
        legacy = source.get('legacy', {})
        rows[key] = {'competition': league, 'season_id': season,
                     'source_presence': source.get('source_presence'),
                     'legacy_coverage': legacy.get('coverage', 'absent'),
                     'legacy_matches': legacy.get('odds_match_ids', 0),
                     'legacy_priced': legacy.get('priced_match_ids', 0),
                     'legacy_results': legacy.get('odds_result_match_ids', 0),
                     'timing_status': 'conflicting_recorded_timing' if conflict else 'recorded_day1' if verified else 'missing_day1_anchor',
                     'recorded_day1_utc': iso(at) if verified else None,
                     'recorded_day1_wat': iso(at, 'Africa/Lagos') if verified else None,
                     'hypothetical_day1_utc': None, 'hypothetical_day1_wat': None,
                     'eligible_for_financial_replay': False, 'evidence': [], 'requirements': []}
    segments, rejected = [], []
    proposals = defaultdict(set)
    for candidate in discovery.get('ordering_candidates', []):
        source_hash = candidate.get('source_sha256')
        ids = candidate.get('candidate_oldest_first')
        if candidate.get('issues') or candidate.get('status') not in ('anchored_candidate', 'cadence_mismatch'):
            rejected.append({'source_sha256': source_hash, 'status': candidate.get('status'), 'issues': candidate.get('issues', [])})
            continue
        # Recompute from the current pinned timing report, rather than trusting
        # cached segment numbers or scraper order labels in discovery metadata.
        checked = assess_order(ids, identities, anchors)
        if checked['status'] not in ('anchored_candidate', 'cadence_mismatch'):
            rejected.append({'source_sha256': source_hash, 'status': checked['status'], 'issues': checked.get('issues', [])})
            continue
        league = checked['leagues'][0]
        ordered = checked['candidate_oldest_first']
        points = [(i, season, anchors[league, season]) for i, season in enumerate(ordered) if (league, season) in anchors]
        for item, left, right in zip(checked['anchor_segments'], points, points[1:]):
            status = segment_status(item['error_seconds'])
            segments.append({'competition': league, 'source_sha256': source_hash, **item,
                             'status': status, 'cause': 'not_established',
                             **reconcile(item, league, ordered, anchors),
                             'from_day1_wat': iso(left[2], 'Africa/Lagos'), 'to_day1_wat': iso(right[2], 'Africa/Lagos')})
            for index in range(left[0] + 1, right[0]):
                key = (league, ordered[index])
                rows[key]['evidence'].append({'source_sha256': source_hash, 'position': 'between_anchors',
                                             'from_season': left[1], 'to_season': right[1], 'segment_status': status})
                if status == 'cadence_agreement':
                    proposals[key].add(left[2] + (index - left[0]) * SEASON_SECONDS)
                else:
                    rows[key]['requirements'].append('Resolve this anchored timing break before estimating inside it.')
        for index, season in enumerate(ordered):
            if index < points[0][0] or index > points[-1][0]:
                point = points[0] if index < points[0][0] else points[-1]
                rows[league, season]['evidence'].append({'source_sha256': source_hash,
                    'position': 'unanchored_prefix' if index < points[0][0] else 'unanchored_suffix',
                    'nearest_anchor': point[1], 'listed_steps': abs(index - point[0])})
    for key, row in rows.items():
        if row['timing_status'] in ('recorded_day1', 'conflicting_recorded_timing'):
            if row['timing_status'] == 'conflicting_recorded_timing':
                row['requirements'].append('Resolve contradictory recorded kickoff evidence.')
            continue
        values = proposals[key]
        blocked = bool(row['requirements'])
        if len(values) > 1:
            row['timing_status'] = 'conflicting_cadence_hypotheses'
            row['requirements'].append('Resolve discovery sequences that propose different times.')
        elif len(values) == 1 and not blocked:
            at = next(iter(values))
            row.update(timing_status='bracketed_cadence_hypothesis', hypothetical_day1_utc=iso(at),
                       hypothetical_day1_wat=iso(at, 'Africa/Lagos'))
            row['requirements'].append('Verify consecutive season links and absence of intervening closures before financial use.')
        elif blocked:
            row['timing_status'] = 'anchored_break_unresolved'
        elif row['evidence']:
            row['timing_status'] = 'unanchored_extrapolation_required'
            row['requirements'].append('Find an earlier/later independent anchor or verified continuity and closure evidence; one-sided cadence is insufficient.')
        else:
            row['requirements'].append('Find verified kickoff or trustworthy chronological evidence for this season.')
    unique_segments = {}
    for segment in segments:
        source_hash = segment.pop('source_sha256')
        key = (segment['competition'], segment['from_season'], segment['to_season'], segment['listed_steps'])
        if key not in unique_segments:
            unique_segments[key] = {**segment, 'source_sha256s': []}
        if source_hash not in unique_segments[key]['source_sha256s']:
            unique_segments[key]['source_sha256s'].append(source_hash)
    segments = list(unique_segments.values())
    seasons = [rows[key] for key in sorted(rows, key=lambda k: (k[0], int(k[1])))]
    return {'version': VERSION, 'policy': {'matchday_seconds': 220, 'normal_season_seconds': SEASON_SECONDS,
            'applied': False, 'financial_replay_enabled': False},
            'seasons': seasons, 'segments': segments, 'recorded_boundaries': unique_boundaries(segments),
            'rejected_candidates': rejected,
            'reconciliation_status_counts': dict(Counter(s['reconciliation_status'] for s in segments)),
            'timing_status_counts': dict(Counter(r['timing_status'] for r in seasons)),
            'segment_status_counts': dict(Counter(s['status'] for s in segments)),
            'limits': ['Recorded fields are scheduled kickoff, not actual start or settlement.',
                       'Hypotheses are review-only; no database, raw file, champion or financial changes.',
                       'Cadence agreement does not prove season adjacency or establish a closure cause.',
                       'Partial seasons remain visible; missing results are not losses.',
                       'Financial eligibility stays false: this report alone does not verify results, champions or market availability.']}


def write_plan(timing_path, discovery_path, destination):
    timing_bytes, discovery_bytes = Path(timing_path).read_bytes(), Path(discovery_path).read_bytes()
    timing, discovery = json.loads(timing_bytes), json.loads(discovery_bytes)
    timing_hash = hashlib.sha256(timing_bytes).hexdigest()
    if discovery.get('timing_report_sha256') != timing_hash:
        raise ValueError('Discovery report refers to a different timing report; use its original pinned report or regenerate discovery.')
    report = build(timing, discovery)
    report['input_sha256s'] = {'timing': timing_hash, 'discovery': hashlib.sha256(discovery_bytes).hexdigest()}
    destination = Path(destination)
    destination.mkdir(parents=True, exist_ok=False)
    (destination / 'report.json').write_text(json.dumps(report, indent=2) + '\n')
    for name in ('seasons', 'segments', 'recorded_boundaries'):
        data = report[name]
        if not data:
            continue
        with (destination / (name + '.csv')).open('w', newline='') as output:
            writer = csv.DictWriter(output, fieldnames=list(data[0]))
            writer.writeheader()
            for row in data:
                writer.writerow({k: json.dumps(v) if isinstance(v, (dict, list)) else v for k, v in row.items()})
    return report


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--timing-report', required=True)
    parser.add_argument('--discovery-report', required=True)
    parser.add_argument('--destination', required=True)
    args = parser.parse_args()
    try:
        report = write_plan(args.timing_report, args.discovery_report, args.destination)
    except (ValueError, OSError, KeyError, TypeError) as error:
        parser.exit(1, 'Timing plan stopped: ' + str(error) + '\n')
    print('Season timing:', report['timing_status_counts'])
    print('Anchored segments:', report['segment_status_counts'])
    print('Reconciled segments:', report['reconciliation_status_counts'])
    print('Unusual recorded boundaries:', len(report['recorded_boundaries']))
    print('Review tables:', str(Path(args.destination) / 'seasons.csv'), str(Path(args.destination) / 'segments.csv'), str(Path(args.destination) / 'recorded_boundaries.csv'))
    print('Review only. No database changes or financial eligibility added.')
