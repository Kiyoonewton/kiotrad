<?php

namespace App\Infrastructure\Diagnostics;

use App\Infrastructure\Provider\ArchivedFeed;
use MongoDB\Driver\Exception\Exception;
use RuntimeException;
use Throwable;

final class RawDocumentAudit
{
    // Messages are fixed validator strings, never provider values, URLs or credentials.
    private const REASONS = [
        'Archived catalog has conflicting league identities.', 'Archived catalog current season is ambiguous.',
        'Fixtures do not match the requested season and league.', 'Invalid, duplicate or cross-league fixture identity.',
        'Fixture team identities are missing or conflicting.', 'Fixture round is outside the verified league format.',
        'Invalid odds collection.', 'Odds cannot be joined unambiguously to the requested league and fixture.',
        'Invalid or duplicate 1X2 outcome.', 'Standings season or league mismatch.',
        'Standings fixtures belong to another season.', 'No unambiguous sixteen-team overall table.',
        'Duplicate or invalid standing identity/rank.', 'Standings arithmetic is inconsistent.', 'Invalid standings number.',
        'Raw evidence checksum mismatch.', 'Legacy provenance is inconsistent.', 'Raw evidence timestamps are inconsistent.',
        'Indexed raw file is missing, unsafe or changed.',
    ];

    public function __construct(private ArchivedFeed $archive, private RawDocumentValidator $validator) {}

    public function run($db, string $folder, callable $emit, callable $progress, ?array $selected = null): array
    {
        $root = realpath($folder);
        if ($root === false || ! is_dir($root) || is_link($folder)) {
            throw new RuntimeException('Mount the retained raw snapshot read-only.');
        }
        $state = $db->local_raw_rebuild->findOne(['_id' => 'state']);
        if (($state['complete'] ?? false) !== true) {
            throw new RuntimeException('Use a completed local snapshot.');
        }
        $counts = $reasons = $phases = [];
        $done = 0;
        $filter = $selected === null ? [] : ['_id' => ['$in' => array_keys($selected)]];
        $total = $db->local_raw_files->countDocuments($filter);
        if ($selected !== null && $total !== count($selected)) {
            throw new RuntimeException('A selected raw file is absent from the active processing index.');
        }
        foreach ($db->local_raw_files->find($filter, ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'], 'sort' => ['_id' => 1]]) as $row) {
            if ($selected !== null && $selected[$row['_id']] !== $row['hash']) {
                throw new RuntimeException('Selected raw identity differs from the original audited file.');
            }
            $context = ['file' => $row['_id'], 'compressed_sha256' => $row['hash'], 'phase' => $row['phase'],
                'season_id' => $row['season'], 'indexed_observed_at' => $row['at']];
            $status = 'ignored_route';
            try {
                $path = realpath($root.'/'.$row['_id']);
                if ($path === false || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR)
                    || is_link($root.'/'.$row['_id']) || ! is_file($path) || hash_file('sha256', $path) !== $row['hash']) {
                    throw new RuntimeException('Indexed raw file is missing, unsafe or changed.');
                }
                if (in_array($row['phase'], ['catalogs', 'fixtures', 'odds', 'standings'], true)) {
                    $record = $this->archive->read($path);
                    $status = $record['status'] !== 200 ? 'non_200_response' :
                        (! is_array($record['feed']->document['data'] ?? null) ? 'missing_data' :
                            $this->validator->validate($db, $record['feed'], $row['phase'], $selected === null ? null :
                                fn ($details) => $emit(['type' => 'validated_odds', ...$context, ...$details])));
                }
            } catch (Throwable $exception) {
                if ($exception instanceof Exception) {
                    throw $exception; // Infrastructure failure must not masquerade as rejected source data.
                }
                $status = 'rejected_now';
                $reason = in_array($exception->getMessage(), self::REASONS, true) ? $exception->getMessage() : 'unclassified_'.class_basename($exception);
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
                $emit(['type' => 'rejection', ...$context, 'reason' => $reason]);
            }
            if (in_array($status, ['missing_fixture_context', 'missing_data', 'non_200_response', 'unknown_league'], true)) {
                $emit(['type' => 'not_validated', ...$context, 'reason' => $status]);
            }
            $phases[$row['phase']][$status] = ($phases[$row['phase']][$status] ?? 0) + 1;
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            if (++$done % 500 === 0 || $done === $total) {
                $progress($done, $total);
            }
        }
        ksort($reasons);

        return ['mode' => 'raw_document_revalidation', 'complete' => true, 'database_writes' => false,
            'rebuild_index_version' => $state['version'] ?? null,
            'validator_hashes' => $this->hashes(), 'phases' => $phases,
            'audit_scope' => $selected === null ? 'full_index' : 'previous_rejections',
            'indexed_files' => $total, 'checked_files' => $done, 'counts' => $counts, 'reasons' => $reasons,
            'historical_rejection_count_reconstructed' => false,
            'notice' => 'Current validation against stored raw_rebuild fixtures, not recovery of the original 57 rejection events. No financial certification.'];
    }

    private function hashes(): array
    {
        $paths = [__FILE__, app_path('Infrastructure/Diagnostics/RawQuoteCoverage.php'), app_path('Infrastructure/Diagnostics/RawDocumentValidator.php')];
        foreach (['RawFixtureContext', 'ArchivedFeed', 'FixtureNormalizer', 'OddsNormalizer', 'StandingsNormalizer'] as $class) {
            $paths[] = app_path('Infrastructure/Provider/'.$class.'.php');
        }

        return array_combine(array_map('basename', $paths), array_map(fn ($path) => hash_file('sha256', $path), $paths));
    }
}
