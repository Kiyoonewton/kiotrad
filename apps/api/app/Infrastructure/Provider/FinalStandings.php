<?php

namespace App\Infrastructure\Provider;

use Throwable;

final class FinalStandings
{
    public function __construct(private SportradarClient $client, private StandingsNormalizer $normalizer) {}

    public function attach(array $report): array
    {
        $report['standings'] = ['status' => 'not_final', 'champion' => null];
        if (! $report['completeness']['results_complete']) {
            return $report;
        }
        try {
            $feed = $this->client->statistics('stats_season_tables/'.$report['season_id']);
            $report['standings'] = $this->normalizer->normalize($feed, $report['season_id'],
                config("provider.competitions.{$report['competition']}.tournament_uid"), $report['matches']);
        } catch (Throwable $exception) {
            report($exception);
            $report['standings'] = ['status' => 'unavailable_or_invalid', 'champion' => null];
        }

        return $report;
    }

    public function latest(?array $previous, array $report, string $captureId): ?array
    {
        if (($report['standings']['status'] ?? null) !== 'verified_final'
            || ! $report['completeness']['scheduled_times_complete']) {
            return $previous;
        }
        $first = min(array_column($report['matches'], 'scheduled_start_at'));
        if ($previous !== null && ($first < $previous['season_first_kickoff_at']
            || ($first === $previous['season_first_kickoff_at'] && $report['season_id'] !== $previous['season_id']))) {
            return $previous;
        }

        return [
            ...$report['standings']['champion'],
            'season_id' => $report['season_id'], 'season_first_kickoff_at' => $first,
            'confirmed_at' => $report['standings']['observed_at'], 'capture_id' => $captureId,
        ];
    }
}
