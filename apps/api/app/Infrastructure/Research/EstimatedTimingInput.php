<?php

namespace App\Infrastructure\Research;

use DateTimeImmutable;
use RuntimeException;

final class EstimatedTimingInput
{
    public function read(string $body): array
    {
        $report = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (($report['version'] ?? '') !== 'older-timing-proposals-1.0.0'
            || ($report['policy']['applied'] ?? null) !== false
            || ($report['policy']['normal_season_seconds'] ?? null) !== 6720) {
            throw new RuntimeException('Use the original older-timing-proposals report.');
        }
        foreach (['timing', 'discovery'] as $name) {
            if (! preg_match('/^[a-f0-9]{64}$/D', $report['input_sha256s'][$name] ?? '')) {
                throw new RuntimeException('Missing pinned timing/discovery provenance.');
            }
        }
        $rows = [];
        foreach ($report['seasons'] as $row) {
            $key = $row['competition'].':'.$row['season_id'];
            if (isset($rows[$key]) || ! in_array($row['competition'], ['virtual-football', 'virtual-bundesliga', 'virtual-euro'], true)
                || ! ctype_digit((string) $row['season_id'])) {
                throw new RuntimeException('Invalid or duplicate season identity.');
            }
            $rows[$key] = $row;
        }
        $starts = [];
        foreach ($rows as $row) {
            if (in_array($row['status'], ['recorded', 'estimated_unbounded'], true)) {
                $time = $row[$row['status'] === 'recorded' ? 'recorded_day1_utc' : 'estimated_day1_utc'];
                $key = $row['competition'].':'.$this->epoch($time);
                if (isset($starts[$key])) {
                    throw new RuntimeException('Ambiguous equal season starts in timing review.');
                }
                $starts[$key] = true;
            }
            if ($row['status'] !== 'estimated_unbounded') {
                continue;
            }
            $at = $this->epoch($row['estimated_day1_utc']);
            if (empty($row['proposals'])) {
                throw new RuntimeException('Estimated date has no proposal provenance.');
            }
            foreach ($row['proposals'] as $proposal) {
                $anchor = $rows[$row['competition'].':'.$proposal['anchor_season']] ?? null;
                if (($anchor['status'] ?? '') !== 'recorded' || ! is_int($proposal['listed_steps']) || $proposal['listed_steps'] < 1
                    || ! preg_match('/^[a-f0-9]{64}$/D', $proposal['source_sha256'] ?? '')
                    || $this->epoch($anchor['recorded_day1_utc']) !== $this->epoch($proposal['anchor_day1_utc'])
                    || $at !== $this->epoch($proposal['hypothetical_day1_utc'])
                    || $at !== $this->epoch($anchor['recorded_day1_utc']) - $proposal['listed_steps'] * 6720) {
                    throw new RuntimeException('Estimated date disagrees with its pinned anchor/steps.');
                }
            }
        }

        return ['sha256' => hash('sha256', $body), 'input_sha256s' => $report['input_sha256s'], 'seasons' => $rows];
    }

    private function epoch(string $at): int
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/D', $at)
            || (new DateTimeImmutable($at))->format('Y-m-d\TH:i:sP') !== $at) {
            throw new RuntimeException('Expected a valid UTC scheduled timestamp.');
        }

        return (new DateTimeImmutable($at))->getTimestamp();
    }
}
