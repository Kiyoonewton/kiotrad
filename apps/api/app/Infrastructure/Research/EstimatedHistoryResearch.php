<?php

namespace App\Infrastructure\Research;

use App\Infrastructure\Provider\SharedCapitalResearch;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class EstimatedHistoryResearch
{
    public function __construct(private SharedCapitalResearch $recorded) {}

    public function calculate(array $input, array $settings, string $asOf, callable $progress): array
    {
        if (! config('kiotrad.local_data_mirror') || ! str_starts_with(config('database.connections.mongodb.database'), 'kiotrad_local_')) {
            throw new RuntimeException('Estimated-history research requires the isolated local mirror.');
        }
        $db = DB::connection('mongodb')->getDatabase();
        $options = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']];
        $rows = array_values($input['seasons']);
        $selected = array_values(array_filter($rows, fn ($r) => $r['status'] === 'estimated_unbounded'));
        foreach (array_keys(config('provider.competitions')) as $league) {
            $recorded = array_values(array_filter($rows, fn ($r) => $r['competition'] === $league && $r['status'] === 'recorded'));
            usort($recorded, fn ($a, $b) => $a['recorded_day1_utc'] <=> $b['recorded_day1_utc']);
            $selected = [...$selected, ...array_slice($recorded, 0, 2)];
        }
        $evidence = $revisions = [];
        foreach ($selected as $i => $row) {
            $progress('estimated_season_evidence', $i, count($selected));
            $captures = iterator_to_array($db->provider_captures->find(['competition' => $row['competition'],
                'season_id' => (string) $row['season_id'], 'observed_at' => ['$lte' => $asOf]],
                [...$options, 'sort' => ['observed_at' => 1, '_id' => 1], 'projection' => ['matches' => 1, 'odds_snapshots' => 1, 'observed_at' => 1]]), false);
            $revisions[$row['competition'].':'.$row['season_id']] = hash('sha256', json_encode($captures, JSON_THROW_ON_ERROR));
            $season = (new EstimatedSeasonEvidence)->fold($row, $captures, $asOf);
            $season['blocked_matchdays'] = array_column(iterator_to_array($db->market_blockages->find([
                'competition' => $row['competition'], 'season_id' => (string) $row['season_id'],
                'classification' => 'operator_confirmed'], $options), false), 'matchday');
            $evidence[] = $season;
        }
        $links = [];
        foreach (['provider_season_transitions', 'provider_catalog_links'] as $collection) {
            foreach ($db->selectCollection($collection)->find([], $options) as $link) {
                if (in_array($link['continuity_status'] ?? '', ['observed_rollover', 'provider_catalog_adjacency'], true)
                    && ($link['observed_at'] ?? $asOf) <= $asOf) {
                    $links[] = $link['competition'].':'.$link['from_season_id'].':'.$link['to_season_id'];
                }
            }
        }
        $progress('recorded_benchmark', 0, 1);
        $recorded = $this->recorded->prepare($asOf, $progress);
        $progress('comparison', 0, 1);
        $result = (new EstimatedHistoryComparison)->calculate($recorded,
            (new EstimatedRunCandidates)->build($evidence, $links), $settings,
            ['timing_review_sha256' => $input['sha256'], 'input_sha256s' => $input['input_sha256s'],
                'database' => config('database.connections.mongodb.database'), 'as_of' => $asOf,
                'candidate_version' => EstimatedRunCandidates::VERSION, 'season_capture_sha256s' => $revisions,
                'model_evidence_sha256' => hash('sha256', json_encode([$evidence, $links], JSON_THROW_ON_ERROR)),
                'recorded_prepared_sha256' => hash('sha256', json_encode($recorded, JSON_THROW_ON_ERROR))]);
        $result['season_diagnostics'] = array_map(fn ($s) => array_intersect_key($s, array_flip([
            'competition', 'season_id', 'estimated', 'start_at', 'complete', 'champion', 'champion_issue', 'conflicts'])), $evidence);
        $progress('comparison', 1, 1);

        return $result;
    }
}
