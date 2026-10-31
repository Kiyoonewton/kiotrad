"""Review one-sided older kickoff hypotheses; never write database timestamps."""
import argparse
import csv
import hashlib
import json
from collections import Counter, defaultdict
from pathlib import Path

from discovery_order import assess_order
from timing_recovery_plan import SEASON_SECONDS, build as timing_plan, iso

VERSION = 'older-timing-proposals-1.0.0'


def build(timing, discovery):
    plan = timing_plan(timing, discovery)
    rows = {(r['competition'], r['season_id']): r for r in plan['seasons']}
    identities, anchors = defaultdict(set), {}
    for key, row in rows.items():
        identities[key[1]].add(key[0])
    for source in timing['seasons']:
        key = (source['competition'], source['season_id'])
        if rows[key]['timing_status'] == 'recorded_day1':
            anchors[key] = source['kiotrad_timing']['day1_utc_epoch']
    proposals, blocked, rejected = defaultdict(list), defaultdict(list), []
    for candidate in discovery.get('ordering_candidates', []):
        digest = candidate.get('source_sha256')
        if candidate.get('issues') or candidate.get('status') not in ('anchored_candidate', 'cadence_mismatch'):
            continue
        if not isinstance(digest, str) or len(digest) != 64 or any(c not in '0123456789abcdef' for c in digest):
            raise ValueError('Discovery candidates need valid source SHA-256 provenance.')
        checked = assess_order(candidate.get('candidate_oldest_first'), identities, anchors)
        if checked['status'] not in ('anchored_candidate', 'cadence_mismatch'):
            rejected.append({'source_sha256': digest, 'status': checked['status']})
            continue
        league = checked['leagues'][0]
        ordered = checked['candidate_oldest_first']
        points = [(i, s, anchors[league, s]) for i, s in enumerate(ordered) if (league, s) in anchors]
        first, second = points[:2]
        # Require the nearest observed interval to contain only recorded seasons.
        # Insert known anchors omitted by sparse lists using their timestamps.
        recorded_between = [(at, s) for (comp, s), at in anchors.items()
                            if comp == league and first[2] <= at <= second[2]]
        recorded_between.sort()
        reason = None
        if first[2] != min(at for (comp, _), at in anchors.items() if comp == league):
            reason = 'prefix_anchor_is_not_earliest_recorded_season'
        elif any((league, s) not in anchors for s in ordered[first[0]:second[0] + 1]):
            reason = 'nearest_anchor_interval_has_untimed_seasons'
        elif any(b[0] - a[0] != SEASON_SECONDS for a, b in zip(recorded_between, recorded_between[1:])):
            reason = 'nearest_recorded_interval_breaks_cadence'
        for index, season in enumerate(ordered[:first[0]]):
            key = (league, season)
            if rows[key]['timing_status'] != 'unanchored_extrapolation_required':
                continue
            evidence = {'source_sha256': digest, 'anchor_season': first[1],
                        'anchor_day1_utc': iso(first[2]), 'listed_steps': first[0] - index,
                        'calibration_seasons': [s for _, s in recorded_between]}
            if reason:
                blocked[key].append({**evidence, 'reason': reason})
                continue
            at = first[2] - evidence['listed_steps'] * SEASON_SECONDS
            proposals[key].append({**evidence, 'hypothetical_day1_utc': iso(at),
                                   'hypothetical_day1_wat': iso(at, 'Africa/Lagos')})
    output = []
    for key, source in rows.items():
        candidates, blocks = proposals[key], blocked[key]
        values = sorted({p['hypothetical_day1_utc'] for p in candidates})
        recorded = source['timing_status'] == 'recorded_day1'
        conflict = 'conflicting' in source['timing_status'] or len(values) > 1
        status = ('recorded' if recorded else 'conflicting' if conflict else
                  'blocked' if blocks else 'estimated_unbounded' if values else 'unavailable')
        output.append({
            'competition': key[0], 'season_id': key[1], 'status': status,
            'legacy_coverage': source['legacy_coverage'], 'legacy_matches': source['legacy_matches'],
            'recorded_day1_utc': source['recorded_day1_utc'],
            'recorded_day1_wat': source['recorded_day1_wat'],
            'estimated_day1_utc': values[0] if status == 'estimated_unbounded' else None,
            'estimated_day1_wat': candidates[0]['hypothetical_day1_wat'] if status == 'estimated_unbounded' else None,
            'uncertainty': None if recorded else 'unknown; missing seasons and unobserved closures can shift this date',
            'eligible_for_financial_replay': False, 'proposals': candidates, 'blocked_evidence': blocks,
        })
    return {'version': VERSION, 'policy': {
        'applied': False, 'financial_replay_enabled': False,
        'normal_season_seconds': SEASON_SECONDS, 'matchday_seconds': 220,
        'assumptions': ['Every listed prefix step is one consecutive season.',
                        'No unobserved closure or schedule change occurred before the anchor.'],
        'limits': ['Agreement between archive files is not independent confirmation.',
                   'No numeric season-ID differences are used as elapsed time.',
                   'Partial seasons are retained; no champions, results or prices are inferred.',
                   'No uncertainty bound or actual settlement time can be established from cadence alone.',
                   'These estimates must not enter the recorded benchmark or overlap decisions.'],
    }, 'status_counts': dict(Counter(r['status'] for r in output)),
        'seasons': output, 'rejected_candidates': rejected}


def write_proposals(timing_path, discovery_path, destination):
    timing_bytes, discovery_bytes = Path(timing_path).read_bytes(), Path(discovery_path).read_bytes()
    timing, discovery = json.loads(timing_bytes), json.loads(discovery_bytes)
    digest = hashlib.sha256(timing_bytes).hexdigest()
    if discovery.get('timing_report_sha256') != digest:
        raise ValueError('Discovery report refers to a different timing report.')
    report = build(timing, discovery)
    report['input_sha256s'] = {'timing': digest, 'discovery': hashlib.sha256(discovery_bytes).hexdigest()}
    destination = Path(destination)
    destination.mkdir(parents=True, exist_ok=False)
    (destination / 'report.json').write_text(json.dumps(report, indent=2) + '\n')
    with (destination / 'seasons.csv').open('w', newline='') as stream:
        writer = csv.DictWriter(stream, fieldnames=list(report['seasons'][0]) if report['seasons'] else ['season_id'])
        writer.writeheader()
        for row in report['seasons']:
            writer.writerow({k: json.dumps(v) if isinstance(v, (list, dict)) else v for k, v in row.items()})
    return report


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--timing-report', required=True)
    parser.add_argument('--discovery-report', required=True)
    parser.add_argument('--destination', required=True)
    args = parser.parse_args()
    try:
        report = write_proposals(args.timing_report, args.discovery_report, args.destination)
    except (ValueError, OSError, KeyError, TypeError, OverflowError) as error:
        parser.exit(1, 'Older timing review stopped: ' + str(error) + '\n')
    print('Timing review:', report['status_counts'])
    print('Review table:', str(Path(args.destination) / 'seasons.csv'))
    print('No database changes. Estimated dates have unknown uncertainty; financial replay stays disabled.')
