<?php

namespace App\Console\Commands;

use App\Domain\Strategies\SimulationDefinitionSnapshot;
use App\Infrastructure\Provider\RawEvidenceRebuilder;
use Illuminate\Console\Command;

class RebuildRawEvidence extends Command
{
    protected $signature = 'provider:rebuild-raw {folder}';

    protected $description = 'Rebuild disposable local provider views from immutable raw responses without network requests';

    public function handle(RawEvidenceRebuilder $rebuilder): int
    {
        $snapshot = json_decode(file_get_contents($this->argument('folder').'/strategy.json'), true, 512, JSON_THROW_ON_ERROR);
        app(SimulationDefinitionSnapshot::class)->verify($snapshot);
        config(['kiotrad.simulation_capital_minor' => $snapshot['capital_minor']]);
        $counts = $rebuilder->rebuild($this->argument('folder'));
        $this->line('Raw rebuild: '.json_encode($counts, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
