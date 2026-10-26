<?php

namespace App\Console\Commands;

use App\Infrastructure\Provider\RawOddsRecovery;
use Illuminate\Console\Command;

class RecoverRawOdds extends Command
{
    protected $signature = 'provider:recover-raw-odds {folder} {--apply : Rebuild missing normalized odds from verified stored responses}';

    protected $description = 'Local-only offline odds audit and recovery; dry run by default';

    public function handle(RawOddsRecovery $recovery): int
    {
        $result = $recovery->recover($this->argument('folder'), (bool) $this->option('apply'),
            function (array $counts) {
                $this->getOutput()->getErrorStyle()->writeln('Raw odds scan: '.json_encode($counts, JSON_THROW_ON_ERROR));
            });
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $result['counts']['invalid_files'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
