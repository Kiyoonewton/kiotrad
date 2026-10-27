"""Preserve non-imported diagnostic JSON and audit discovery-order candidates.

Read-only with respect to source files and databases. Output is a new local
package; no timestamps, provider season links or market-blockage claims added.
"""
import argparse
import hashlib
import json
import sys
from collections import Counter, defaultdict
from pathlib import Path

from discovery_order import assess_order, failure_evidence
from timing_audit import LEAGUES, ROUTE


def audit(roots, timing_report, imported_manifest, destination):
    roots = [Path(r).resolve() for r in roots]
    destination = Path(destination).resolve()
    if destination.exists() or any(not p.is_dir() for p in roots):
        raise ValueError('Use existing source directories and a new destination.')
    if any(destination == p or destination in p.parents or p in destination.parents for p in roots):
        raise ValueError('Destination must be outside all source directories.')
    timing_bytes = Path(timing_report).read_bytes()
    timing = json.loads(timing_bytes)
    if timing.get('version') != 'historical-timing-audit-2.0.0':
        raise ValueError('Use a version-2 source-aware timing report.')
    manifest = json.loads(Path(imported_manifest).read_text())
    known = {e['sha256'] for e in manifest['files']}
    identities, anchors = defaultdict(set), {}
    for row in timing['seasons']:
        if row['kiotrad']['present']:
            identities[row['season_id']].add(row['competition'])
        t = row.get('kiotrad_timing', {})
        if t.get('status') == 'verified_anchor_available' and type(t.get('day1_utc_epoch')) is int:
            anchors[row['competition'], row['season_id']] = t['day1_utc_epoch']
    destination.mkdir(parents=True)
    (destination / 'files').mkdir()
    counts = Counter()
    preserved, diagnostics, failures, pending = {}, [], [], []
    for root in roots:
        for path in root.rglob('*.json'):
            if path.is_symlink() or not path.is_file():
                continue
            counts['files'] += 1
            if counts['files'] % 1000 == 0:
                print(f"Inspected {counts['files']} files...", file=sys.stderr, flush=True)
            try:
                if path.stat().st_size > 20 * 1024 * 1024:
                    raise ValueError('file_over_20_mib')
                body = path.read_bytes()
                payload = json.loads(body)
                if not isinstance(payload, dict):
                    raise ValueError('not_a_json_object')
                digest = hashlib.sha256(body).hexdigest()
                route_text = str(payload.get('queryUrl', ''))
                route = ROUTE.fullmatch(route_text)
                docs = payload.get('doc', [])
                if not isinstance(docs, list):
                    raise ValueError('invalid_doc_array')
                events = [e for doc in docs if isinstance(doc, dict) and isinstance(doc.get('data'), dict)
                          for e in doc['data'].get('odds', [])]
                if route:
                    for event in events:
                        league = LEAGUES.get(str(event.get('tournament_id', '')).split(':')[-1])
                        if league:
                            identities[route.group(1)].add(league)
                if digest in known:
                    counts['already_packaged'] += 1
                    continue
                if digest not in preserved:
                    name = digest + '.json'
                    (destination / 'files' / name).write_bytes(body)
                    preserved[digest] = {'file': name, 'sha256': digest, 'sources': []}
                preserved[digest]['sources'].append({'root': str(root), 'filename': str(path.relative_to(root))})
                item = {'sha256': digest, 'source_filename': path.name}
                if route and not events:
                    has_odds = any(isinstance(d, dict) and isinstance(d.get('data'), dict)
                                   and isinstance(d['data'].get('odds'), list) for d in docs)
                    item.update(kind='empty_odds_response' if has_odds else 'provider_error_or_missing_odds',
                                season_id=route.group(1), matchday=int(route.group(2)),
                                cause='not_established', response_metadata=[{k: v for k, v in d.items() if k != 'data'}
                                                                         for d in docs if isinstance(d, dict)])
                elif route:
                    item.update(kind='additional_match_response', season_id=route.group(1), matchday=int(route.group(2)))
                elif route_text:
                    item.update(kind='invalid_or_unsupported_route', route=route_text)
                else:
                    item.update(kind='collection_metadata', evidence=failure_evidence(payload),
                                declared_competition=payload.get('competition'),
                                reported_discovered_at=payload.get('discovered_at'),
                                reported_result=payload.get('result'),
                                discovery_complete=payload.get('discovery_complete'))
                    # season_ids is a work/resume list, never a chronological sequence.
                    for field in ('discovery_ids', 'order'):
                        if isinstance(payload.get(field), list) and payload[field]:
                            pending.append((item, field, payload[field], payload.get('competition')))
                diagnostics.append(item)
            except (OSError, ValueError, KeyError, TypeError, AttributeError) as error:
                failures.append({'file': str(path), 'error_type': type(error).__name__})
    sequences = []
    seen_sequences = set()
    for item, field, ids, declared in pending:
        identity = (item['sha256'], field)
        if identity in seen_sequences:
            continue
        seen_sequences.add(identity)
        sequences.append({'source_sha256': item['sha256'], 'source_filename': item['source_filename'], 'field': field,
                          **assess_order(ids, identities, anchors, declared)})
    for item in diagnostics:
        if item.get('season_id'):
            item['known_leagues'] = sorted(identities.get(item['season_id'], set()))
    report = {'version': 'legacy-discovery-evidence-1.0.0', 'scan': dict(counts),
              'preserved_unique_files': len(preserved),
              'timing_report_sha256': hashlib.sha256(timing_bytes).hexdigest(),
              'diagnostics': diagnostics, 'ordering_candidates': sequences, 'read_failures': failures,
              'ordering_status_counts': dict(Counter(s['status'] for s in sequences)),
              'limits': ['No estimated kickoff, database writes or verified season links produced.',
                         'Scraper labels, work lists and filenames are not trusted league/adjacency evidence.',
                         'Empty odds responses do not establish a historical betting-market blockage.',
                         'Cadence agreement is checked only between available verified anchors.']}
    (destination / 'report.json').write_text(json.dumps(report, indent=2) + '\n')
    (destination / 'manifest.json').write_text(json.dumps({'version': report['version'],
        'report_sha256': hashlib.sha256((destination / 'report.json').read_bytes()).hexdigest(),
        'files': list(preserved.values()), 'complete': not failures}, indent=2) + '\n')
    return report


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('roots', nargs='+')
    parser.add_argument('--timing-report', required=True)
    parser.add_argument('--imported-manifest', required=True)
    parser.add_argument('--destination', required=True)
    args = parser.parse_args()
    report = audit(args.roots, args.timing_report, args.imported_manifest, args.destination)
    print('Scan:', report['scan'])
    print('Preserved unique files:', report['preserved_unique_files'])
    print('Diagnostic rows:', len(report['diagnostics']))
    print('Ordering candidates:', report['ordering_status_counts'])
    print('Read failures:', len(report['read_failures']))
    print('Report:', str(Path(args.destination) / 'report.json'))
    if report['read_failures']:
        sys.exit(1)
