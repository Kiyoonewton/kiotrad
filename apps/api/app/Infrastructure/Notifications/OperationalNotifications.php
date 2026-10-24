<?php

namespace App\Infrastructure\Notifications;

use App\Infrastructure\Provider\CollectionGap;
use Illuminate\Support\Facades\DB;

final class OperationalNotifications
{
    public function scan(NotificationOutbox $outbox, string $since): void
    {
        $db = DB::connection('mongodb')->getDatabase();
        $options = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']];
        foreach ($db->collector_states->find([], $options) as $state) {
            $competition = $state['_id'];
            $gap = (new CollectionGap)->detect($competition, $state, now('UTC')->toIso8601String(), false);
            if ($gap) {
                $outbox->enqueue('gap:'.$gap['id'], 'interrupted', [
                    'League' => $competition, 'Expected collection UTC' => $gap['expected_at'],
                    'Reason' => $gap['reason'], 'Action' => 'Collection and recovery will retry; source data is retained.',
                ]);
            }
            $last = $state['last_collection_gap'] ?? null;
            if ($last && ($last['resumed_at'] ?? '') >= $since) {
                $outbox->enqueue('resume:'.$last['id'], 'resumed', ['League' => $competition,
                    'Resumed UTC' => $last['resumed_at'], 'Pending seasons' => count($state['pending_seasons'] ?? [])]);
                if (empty($state['pending_seasons'])) {
                    $outbox->enqueue('recovered:'.$last['id'], 'recovered', ['League' => $competition,
                        'Scope' => 'Known recovery queue is empty; not a claim of unavailable historical coverage.']);
                }
            }
            foreach ($state['pending_seasons'] ?? [] as $season => $job) {
                if (($job['failures'] ?? 0) >= 3) {
                    $outbox->enqueue('repair:'.$competition.':'.$season, 'pending', ['League' => $competition,
                        'Season' => $season, 'Reason' => $job['reason'] ?? 'recovery_pending',
                        'Attempts' => $job['failures'], 'Next retry UTC' => $job['next_attempt_at'],
                        'Action' => 'Retained in recovery queue; not classified as a market block.']);
                }
            }
            foreach ($state['planned_rounds'] ?? [] as $round) {
                if (($round['status'] ?? '') === 'availability_unknown') {
                    $outbox->enqueue('unknown:'.$competition.':'.$round['season_id'].':'.$round['matchday'], 'unknown_market', [
                        'League' => $competition, 'Season' => $round['season_id'], 'Matchday' => $round['matchday'],
                        'Action' => 'Odds unavailable or unverified. Retry continues; blockage is not confirmed.',
                    ]);
                }
            }
        }
        foreach ($db->market_blockages->find(['classification' => 'operator_confirmed', 'observed_at' => ['$gte' => $since]], $options) as $block) {
            $outbox->enqueue('block:'.$block['_id'], 'blocked_market', ['League' => $block['competition'],
                'Season' => $block['season_id'], 'Matchday' => $block['matchday'],
                'Reason' => $block['reason'], 'Evidence SHA256' => $block['evidence_sha256'],
                'Scope' => 'Operator confirmation; fixtures and results still require collection.']);
        }
    }
}
