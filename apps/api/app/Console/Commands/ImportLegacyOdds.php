<?php

namespace App\Console\Commands;

use App\Infrastructure\Provider\LegacyOddsImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportLegacyOdds extends Command
{
    protected $signature = 'provider:import-legacy-odds {folder} {--apply : Preserve source files and import missing odds}';

    protected $description = 'Validate legacy plain JSON odds files against existing KioTrad fixtures; dry run by default';

    public function handle(LegacyOddsImporter $importer): int
    {
        $folder = $this->argument('folder');
        if (! is_dir($folder) || is_link($folder)) {
            $this->error('Supply a regular directory of legacy JSON responses.');

            return self::FAILURE;
        }
        $files = glob(rtrim($folder, '/').'/*.json');
        sort($files);
        $manifestPath = dirname(rtrim($folder, '/')).'/manifest.json';
        $manifest = is_file($manifestPath) ? json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR) : null;
        $checksums = $manifest === null ? null : array_column($manifest['files'] ?? [], 'sha256', 'file');
        if ($manifest !== null && (empty($checksums) || count($checksums) !== count($manifest['files'] ?? []))) {
            $this->error('Invalid or duplicate legacy package manifest entries.');

            return self::FAILURE;
        }
        if ($checksums !== null && array_diff(array_keys($checksums), array_map('basename', $files)) !== []) {
            $this->error('Package files are missing; complete the upload before importing.');

            return self::FAILURE;
        }
        $sourceNames = $manifest === null ? [] : array_column($manifest['files'] ?? [], 'source_filename', 'file');
        $counts = [];
        $failed = false;
        foreach ($files as $file) {
            try {
                if ($checksums !== null && (! isset($checksums[basename($file)])
                    || ! hash_equals($checksums[basename($file)], hash_file('sha256', $file)))) {
                    throw new \RuntimeException('Package checksum mismatch; file rejected.');
                }
                $result = $importer->import($file, (bool) $this->option('apply'), $sourceNames[basename($file)] ?? null);
                $failed = $failed || $result['status'] === 'missing_round_fixtures';
            } catch (Throwable $exception) {
                $result = ['file' => basename($file), 'status' => 'rejected', 'reason' => $exception->getMessage()];
                $failed = true;
            }
            $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
        $this->line(json_encode(['mode' => $this->option('apply') ? 'apply' : 'read_only', 'files' => count($files), 'counts' => $counts], JSON_THROW_ON_ERROR));

        return $failed || $files === [] ? self::FAILURE : self::SUCCESS;
    }
}
