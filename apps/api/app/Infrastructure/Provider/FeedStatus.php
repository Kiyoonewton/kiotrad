<?php

namespace App\Infrastructure\Provider;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class FeedStatus
{
    public function all(): array
    {
        try {
            $db = DB::connection('mongodb')->getDatabase();
            $rows = [];
            foreach (config('kiotrad.competitions') as $competition) {
                $options = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']];
                $checkpoint = $db->provider_checkpoints->findOne(['_id' => $competition['id']], $options);
                $state = $db->collector_states->findOne(['_id' => $competition['id']], $options);
                $capture = $checkpoint ? $db->provider_captures->findOne(['_id' => $checkpoint['capture_id']], $options) : null;
                $rows[] = $this->summarize($competition, $capture, $state ?? []);
            }

            return $rows;
        } catch (Throwable) {
            return array_map(fn ($c) => $this->summarize($c, null, [], 'unavailable'), config('kiotrad.competitions'));
        }
    }

    public function summarize(array $competition, ?array $capture, array $state, ?string $override = null): array
    {
        $observed = $capture['observed_at'] ?? null;
        $age = $observed ? CarbonImmutable::parse($observed)->diffInSeconds(CarbonImmutable::now('UTC'), false) : null;
        $status = $observed ? ($age >= 0 && $age <= 120 ? 'fresh' : 'stale') : 'not_connected';
        if ($observed && $age >= 0 && $status === 'stale' && isset($state['next_attempt_at'])
            && CarbonImmutable::parse($state['next_attempt_at'])->isFuture()) {
            $status = 'scheduled';
        }
        if (($state['failure_count'] ?? 0) > 0) {
            $status = 'retrying';
        }

        return [
            ...$competition,
            'feed_status' => $override ?? $status,
            'season_id' => $capture['season_id'] ?? null,
            'matchday' => $capture['odds_matchday'] ?? null,
            'last_success_at' => $observed,
            'next_attempt_at' => $state['next_attempt_at'] ?? null,
            'fixture_count' => $capture['fixture_count'] ?? null,
            'settled_count' => $capture['settled_count'] ?? null,
            'completeness' => $capture['completeness'] ?? null,
            'market_observation' => $state['market_observation'] ?? $capture['market_observation'] ?? 'not_assessed',
            'unresolved_seasons' => array_map('strval', array_keys($state['pending_seasons'] ?? [])),
            'planned_rounds' => $state['planned_rounds'] ?? [],
            'latest_champion' => $state['latest_champion'] ?? null,
            'data_complete' => false,
        ];
    }
}
