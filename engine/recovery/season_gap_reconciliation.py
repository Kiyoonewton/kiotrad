"""Explain sparse discovery lists using recorded kickoff order, never ID arithmetic."""
from collections import Counter

SEASON_SECONDS = 6720


def reconcile(segment, league, listed_ids, anchors):
    start = anchors[league, segment['from_season']]
    end = anchors[league, segment['to_season']]
    points = sorted((at, season) for (competition, season), at in anchors.items()
                    if competition == league and start <= at <= end)
    # Equal recorded starts cannot establish an ordering between seasons.
    if any(count > 1 for count in Counter(at for at, _ in points).values()):
        return {'reconciliation_status': 'ambiguous_recorded_order',
                'omitted_recorded_seasons': [], 'reconciled_steps': None,
                'remaining_gap_seconds': None, 'unusual_recorded_boundaries': []}
    omitted = [season for at, season in points if start < at < end and season not in listed_ids]
    steps = segment['listed_steps'] + len(omitted)
    remainder = end - start - steps * SEASON_SECONDS
    status = ('known_seasons_omitted' if remainder == 0 else 'known_seasons_omitted_with_remaining_gap') if omitted else (
        'cadence_agreement' if remainder == 0 else 'unexplained_timing_gap')
    boundaries = []
    for left, right in zip(points, points[1:]):
        gap = right[0] - left[0]
        if gap != SEASON_SECONDS:
            boundaries.append({'competition': league, 'from_season': left[1], 'to_season': right[1],
                               'from_day1_utc_epoch': left[0], 'to_day1_utc_epoch': right[0],
                               'observed_gap_seconds': gap, 'normal_cadence_difference_seconds': gap - SEASON_SECONDS,
                               'cause': 'not_established', 'adjacency': 'consecutive_in_supplied_recorded_anchors_only'})
    return {'reconciliation_status': status, 'omitted_recorded_seasons': omitted,
            'reconciled_steps': steps, 'remaining_gap_seconds': remainder,
            'unusual_recorded_boundaries': boundaries}


def unique_boundaries(segments):
    known = {}
    for segment in segments:
        for boundary in segment['unusual_recorded_boundaries']:
            key = (boundary['competition'], boundary['from_season'], boundary['to_season'])
            if key not in known:
                known[key] = {**boundary, 'source_sha256s': []}
            known[key]['source_sha256s'] = sorted(set(known[key]['source_sha256s'] + segment['source_sha256s']))
    return sorted(known.values(), key=lambda b: (b['competition'], b['from_day1_utc_epoch']))
