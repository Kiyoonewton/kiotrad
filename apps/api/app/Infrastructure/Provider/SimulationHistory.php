<?php

namespace App\Infrastructure\Provider;

use Illuminate\Support\Facades\DB;

class SimulationHistory
{
    public function __construct(private ChampionMonitoring $monitoring) {}

    public function seasons(string $competition, ?string $from = null, ?string $to = null): array
    {
        $filter = $competition === 'all' ? [] : ['capture.competition' => $competition];
        $dates = $from === null ? ['$or' => [['start_at' => null], ['start_at' => ['$lte' => $to ?? now('UTC')->toIso8601String()]]]]
            : ['$and' => [['end_at' => ['$gte' => $from]], ['start_at' => ['$lte' => $to ?? now('UTC')->toIso8601String()]]]];

        return DB::connection('mongodb')->getDatabase()->season_checkpoints->aggregate([
            ['$lookup' => ['from' => 'provider_captures', 'localField' => 'capture_id', 'foreignField' => '_id', 'as' => 'capture']],
            ['$unwind' => '$capture'], ['$match' => $filter],
            ['$project' => ['_id' => 0, 'competition' => '$capture.competition', 'season_id' => 1,
                'start_at' => ['$reduce' => ['input' => ['$ifNull' => ['$capture.matches.scheduled_start_at', []]],
                    'initialValue' => null, 'in' => ['$cond' => [
                        ['$and' => [['$ne' => ['$$this', null]], ['$or' => [['$eq' => ['$$value', null]], ['$lt' => ['$$this', '$$value']]]]]],
                        '$$this', '$$value',
                    ]]]],
                'end_at' => ['$reduce' => ['input' => ['$ifNull' => ['$capture.matches.scheduled_start_at', []]],
                    'initialValue' => null, 'in' => ['$cond' => [
                        ['$and' => [['$ne' => ['$$this', null]], ['$or' => [['$eq' => ['$$value', null]], ['$gt' => ['$$this', '$$value']]]]]],
                        '$$this', '$$value',
                    ]]]]]],
            ['$match' => $dates], ['$sort' => ['start_at' => -1, 'competition' => 1, 'season_id' => -1]],
        ], ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']])->toArray();
    }

    public function rows(array $seasons): array
    {
        $rows = [];
        $cutoff = now('UTC')->subDays(7)->toIso8601String();
        foreach ($seasons as $season) {
            $ttl = ($season['start_at'] ?? '') >= $cutoff ? SimulationCache::RECENT_TTL : SimulationCache::HISTORICAL_TTL;
            $rows = [...$rows, ...$this->monitoring->forSeason($season['competition'], (string) $season['season_id'], $ttl)];
        }

        return $rows;
    }

    public function page(int $page, int $perPage, string $competition, ?string $from = null, ?string $to = null): array
    {
        $seasons = $this->seasons($competition, $from, $to);
        $total = count($seasons);

        return ['schema_version' => '1', 'generated_at' => now('UTC')->toIso8601String(), 'mode' => 'event_order_simulation',
            'rows' => $this->rows(array_slice($seasons, ($page - 1) * $perPage, $perPage)),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'unit' => 'seasons',
                'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))]];
    }
}
