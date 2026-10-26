<?php

namespace App\Domain\Strategies;

use RuntimeException;

final class SimulationDefinitionSnapshot
{
    public function current(): array
    {
        $hashes = [];
        foreach ([...glob(app_path('Domain/Strategies/*.php')), ...glob(app_path('Domain/Research/*.php')), app_path('Domain/Paper/RecoveryStake.php')] as $path) {
            $hashes[basename($path)] = hash_file('sha256', $path);
        }
        ksort($hashes);

        return ['schema_version' => 1, 'strategies' => (new StrategyCatalog)->all(),
            'replay_version' => RunReplay::EVENT_VERSION, 'code_hashes' => $hashes,
            'capital_minor' => config('kiotrad.simulation_capital_minor')];
    }

    public function verify(array $snapshot): void
    {
        $local = $this->current();
        if (($snapshot['strategies'] ?? null) !== $local['strategies'] || ($snapshot['code_hashes'] ?? null) !== $local['code_hashes']
            || ($snapshot['replay_version'] ?? null) !== $local['replay_version']
            || ! is_int($snapshot['capital_minor'] ?? null) || $snapshot['capital_minor'] < 10000) {
            throw new RuntimeException('Strategy snapshot differs from local code or has invalid settings; align code before replay.');
        }
    }
}
