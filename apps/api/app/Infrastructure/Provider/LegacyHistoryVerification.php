<?php

namespace App\Infrastructure\Provider;

final class LegacyHistoryVerification
{
    public function inspect(array $record, ?array $capture, bool $checkpointExists): array
    {
        $plan = $record['plan'] ?? [];
        $expected = $plan['capture'] ?? [];
        $needed = ($plan['new_matches'] ?? 0) + ($plan['new_prices'] ?? 0) > 0;
        $issues = [];
        if (($record['status'] ?? '') !== 'complete' || $expected === []) {
            $issues[] = 'incomplete_import_plan';
        }
        $expectedHash = $this->digest($expected);
        $actualHash = null;
        if ($needed) {
            if ($capture === null) {
                $issues[] = 'missing_capture';
            } else {
                unset($capture['_id']);
                $actualHash = $this->digest($capture);
                if ($actualHash !== $expectedHash) {
                    $issues[] = 'capture_differs_from_pinned_plan';
                }
            }
            if (! $checkpointExists) {
                $issues[] = 'missing_season_checkpoint';
            }
        }

        return ['season' => ($expected['competition'] ?? '?').':'.($expected['season_id'] ?? '?'),
            'ledger_id' => (string) $record['_id'], 'status' => $issues !== [] ? 'failed' : ($needed ? 'verified' : 'raw_preserved_only'),
            'fixtures' => count($expected['matches'] ?? []), 'quotes' => count($expected['odds_snapshots'] ?? []),
            'untimed' => count(array_filter($expected['matches'] ?? [], fn ($m) => empty($m['scheduled_start_at']))),
            'source_sha256s' => $expected['provenance']['source_sha256s'] ?? [],
            'expected_sha256' => $expectedHash, 'actual_sha256' => $actualHash, 'issues' => $issues];
    }

    private function digest(array $value): string
    {
        return hash('sha256', json_encode($this->canonical($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonical($item);
            }
        }

        return $value;
    }
}
