<?php

namespace App\Console\Commands;

use App\Infrastructure\Diagnostics\RawDocumentAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class AuditRawDocuments extends Command
{
    protected $signature = 'provider:audit-raw-documents {folder} {--report=}';

    protected $description = 'Local read-only revalidation of indexed raw documents; never rebuilds or activates views';

    public function handle(RawDocumentAudit $audit): int
    {
        $database = (string) config('database.connections.mongodb.database');
        if (! config('kiotrad.local_data_mirror') || ! preg_match('/^kiotrad_local_[0-9TZ]+$/D', $database)) {
            $this->error('Raw document audit requires a completed timestamped local mirror.');

            return self::FAILURE;
        }
        $output = null;
        try {
            $path = $this->option('report');
            if (! is_string($path) || ($output = @fopen($path, 'x')) === false) {
                throw new RuntimeException('Choose a new writable --report path. Existing reports are preserved.');
            }
            $emit = function (array $row) use ($output): void {
                $line = json_encode($row, JSON_THROW_ON_ERROR).PHP_EOL;
                if (fwrite($output, $line) !== strlen($line)) {
                    throw new RuntimeException('Audit report write failed.');
                }
            };
            $emit(['type' => 'start', 'database' => $database, 'audited_at' => now('UTC')->toIso8601String(),
                'validator_sha256' => hash_file('sha256', app_path('Infrastructure/Diagnostics/RawDocumentValidator.php'))]);
            $summary = $audit->run(DB::connection('mongodb')->getDatabase(), $this->argument('folder'), $emit,
                fn ($done, $total) => $this->line("Raw document audit: {$done}/{$total}"));
            $emit(['type' => 'summary', 'database' => $database, ...$summary]);
            $this->line(json_encode($summary, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Raw audit stopped; a report without a complete summary is incomplete. No database changes were requested.');

            return self::FAILURE;
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }
        }
    }
}
