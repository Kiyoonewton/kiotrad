<?php

namespace App\Infrastructure\Provider;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class CalendarCoverage
{
    public function view(): array
    {
        try {
            $db = DB::connection('mongodb')->getDatabase();
            $checkpoints = $db->season_checkpoints->find([], ['sort' => ['_id' => 1], 'projection' => ['capture_id' => 1, 'season_id' => 1, 'odds_evidence_revision' => 1]])->toArray();
            $leagues = [];
            foreach ($checkpoints as $checkpoint) {
                $identity = explode(':', $checkpoint['_id'], 2);
                $competition = $identity[0];
                $key = 'calendar-coverage:v2:'.hash('sha256', json_encode([config('database.connections.mongodb.database'), config('database.connections.mongodb.dsn'), $checkpoint]));
                $rows = Cache::store('redis')->remember($key, SimulationCache::RECENT_TTL, function () use ($db, $checkpoint, $competition) {
                    return $db->provider_captures->aggregate([
                        ['$match' => ['competition' => $competition, 'season_id' => (string) $checkpoint['season_id']]],
                        ['$unwind' => '$matches'],
                        ['$match' => ['matches.status' => 'settled', 'matches.provider_match_id' => ['$type' => 'string']]],
                        ['$group' => ['_id' => '$matches.provider_match_id',
                            'start' => ['$max' => ['$ifNull' => ['$matches.scheduled_start_at', null]]]]],
                        ['$group' => ['_id' => null, 'fixtures' => ['$sum' => 1], 'starts' => ['$addToSet' => '$start'],
                            'unknown' => ['$sum' => ['$cond' => [['$eq' => ['$start', null]], 1, 0]]]]],
                    ], ['allowDiskUse' => true, 'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']])->toArray();
                });
                foreach ($rows as $row) {
                    $leagues[$competition] ??= ['_id' => $competition, 'fixtures' => 0, 'unknown' => 0, 'starts' => []];
                    $leagues[$competition]['fixtures'] += $row['fixtures'];
                    $leagues[$competition]['unknown'] += $row['unknown'];
                    $leagues[$competition]['starts'] = array_unique([...$leagues[$competition]['starts'], ...$row['starts']]);
                }
            }

            return $this->summarize(array_values($leagues));
        } catch (Throwable $exception) {
            report($exception);

            return ['status' => 'unavailable', 'leagues' => []];
        }
    }

    public function summarize(array $rows): array
    {
        $leagues = [];
        foreach ($rows as $row) {
            $days = [];
            $starts = [];
            $ranges = [];
            foreach ($row['starts'] ?? [] as $value) {
                if (! is_string($value)) {
                    continue;
                }
                try {
                    $time = CarbonImmutable::parse($value)->utc();
                } catch (Throwable) {
                    continue;
                }
                if ($time->isFuture()) {
                    continue;
                }
                $starts[] = $time->toIso8601String();
                $day = $time->setTimezone('Africa/Lagos')->format('Y-m-d');
                $days[$day] = true;
                $utc = $time->toIso8601String();
                $ranges[$day] ??= ['date' => $day, 'first_at' => $utc, 'last_at' => $utc];
                $ranges[$day]['first_at'] = min($ranges[$day]['first_at'], $utc);
                $ranges[$day]['last_at'] = max($ranges[$day]['last_at'], $utc);
            }
            sort($starts);
            $dates = array_keys($days);
            sort($dates);
            ksort($ranges);
            $leagues[] = ['competition' => $row['_id'], 'dates' => $dates, 'days' => array_values($ranges),
                'first_at' => $starts[0] ?? null, 'last_at' => $starts ? end($starts) : null,
                'settled_fixtures' => $row['fixtures'], 'unknown_timing_fixtures' => $row['unknown']];
        }

        return ['status' => 'available', 'leagues' => $leagues];
    }
}
