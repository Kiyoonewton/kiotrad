"""Assess discovery candidates without promoting scraper order to verified links."""


def assess_order(ids, identities, anchors, declared=None, season_seconds=6720):
    if not isinstance(ids, list) or not ids or any(not isinstance(s, str) or not s.isdigit() for s in ids):
        return {'status': 'invalid_sequence', 'eligible_for_estimation': False}
    issues = []
    if len(set(ids)) != len(ids):
        issues.append('duplicate_ids')
    unknown = [s for s in ids if len(identities.get(s, set())) != 1]
    leagues = {next(iter(identities[s])) for s in ids if len(identities.get(s, set())) == 1}
    if unknown:
        issues.append('unknown_or_conflicting_league')
    if len(leagues) > 1:
        issues.append('mixed_leagues')
    if declared and leagues and leagues != {declared}:
        issues.append('declared_league_mismatch')
    result = {'status': 'rejected_identity' if issues else 'insufficient_anchors',
              'issues': issues, 'season_count': len(ids), 'leagues': sorted(leagues),
              'unknown_or_conflicting_ids': unknown, 'eligible_for_estimation': False}
    if issues:
        return result
    league = next(iter(leagues))
    observed = [(i, s, anchors[league, s]) for i, s in enumerate(ids) if (league, s) in anchors]
    if len(observed) < 2:
        return {**result, 'anchor_count': len(observed)}
    gaps = [b[2] - a[2] for a, b in zip(observed, observed[1:])]
    if all(g > 0 for g in gaps):
        ordered = ids[:]
        direction = 'oldest_first'
    elif all(g < 0 for g in gaps):
        ordered = list(reversed(ids))
        direction = 'newest_first'
    else:
        return {**result, 'status': 'anchor_order_conflict', 'anchor_count': len(observed)}
    points = [(i, s, anchors[league, s]) for i, s in enumerate(ordered) if (league, s) in anchors]
    segments = []
    for a, b in zip(points, points[1:]):
        actual = b[2] - a[2]
        steps = b[0] - a[0]
        segments.append({'from_season': a[1], 'to_season': b[1], 'listed_steps': steps,
                         'observed_gap_seconds': actual, 'hypothesized_gap_seconds': steps * season_seconds,
                         'error_seconds': actual - steps * season_seconds})
    mismatch = any(s['error_seconds'] != 0 for s in segments)
    return {**result, 'status': 'cadence_mismatch' if mismatch else 'anchored_candidate',
            'anchor_count': len(points), 'observed_direction': direction,
            'candidate_oldest_first': ordered, 'anchor_segments': segments,
            'unanchored_prefix_seasons': points[0][0],
            'unanchored_suffix_seasons': len(ordered) - 1 - points[-1][0],
            'notice': 'Anchored agreement does not prove adjacency or cadence in the unanchored history.'}


def failure_evidence(payload):
    evidence = []
    error = payload.get('error')
    if isinstance(error, str):
        text = error.lower()
        kind = ('manual_cancellation' if 'ctrl+c' in text or 'aborted' in text else
                'archive_ui_failure' if 'show more' in text or 'resolve archive row' in text else
                'collection_timeout' if 'waiting failed' in text or 'timeout' in text else 'collection_error')
        evidence.append({'kind': kind, 'reported_error': error})
    for row in payload.get('processed', []):
        if isinstance(row, dict) and row.get('status') in ('network', 'incomplete'):
            evidence.append({'kind': 'collector_reported_' + row['status'], 'season_id': row.get('season_id')})
    return evidence
