<?php

namespace App\Console\Commands;

use App\Infrastructure\Research\EstimatedHistoryResearch;
use App\Infrastructure\Research\EstimatedTimingInput;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ReplayEstimatedHistory extends Command
{
    protected $signature = 'strategy:replay-estimated-history {report}
        {--capital=50000} {--capital-mode=compound} {--profit-stop=0}
        {--qualification=5} {--attempts=4} {--allowance=1}
        {--strategy=all} {--league=all}';

    protected $description = 'Local research only: compare recorded evidence with unbounded older timing hypotheses';

    public function handle(EstimatedHistoryResearch $research): int
    {
        if (! config('kiotrad.local_data_mirror') || ! str_starts_with(config('database.connections.mongodb.database'), 'kiotrad_local_')) {
            $this->error('Use the isolated local mirror; production research replay is disabled.');

            return self::FAILURE;
        }
        $settings = [];
        foreach (['capital', 'capital-mode', 'profit-stop', 'qualification', 'attempts', 'allowance', 'strategy', 'league'] as $key) {
            $settings[str_replace('-', '_', $key)] = $this->option($key);
        }
        $validator = Validator::make($settings, [
            'capital' => 'required|integer|min:100|max:10000000', 'profit_stop' => 'required|integer|min:0|max:10000000',
            'qualification' => 'required|integer|min:1|max:30', 'attempts' => 'required|integer|min:1|max:10',
            'allowance' => ['required', Rule::in([1, 2])], 'capital_mode' => ['required', Rule::in(['compound', 'fixed'])],
            'strategy' => ['required', Rule::in(['all', 'champion-next-win', 'champion-next-season'])],
            'league' => ['required', Rule::in(['all', 'virtual-football', 'virtual-bundesliga', 'virtual-euro'])],
        ]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }
        foreach (['capital', 'profit_stop', 'qualification', 'attempts', 'allowance'] as $key) {
            $settings[$key] = (int) $settings[$key];
        }
        $body = file_get_contents($this->argument('report'));
        if ($body === false) {
            $this->error('Cannot read the timing proposal report.');

            return self::FAILURE;
        }
        $input = (new EstimatedTimingInput)->read($body);
        $progress = function ($phase, $done, $total): void {
            fwrite(STDERR, $phase.': '.$done.'/'.$total.PHP_EOL);
        };
        $result = $research->calculate($input, $settings, now('UTC')->toIso8601String(), $progress);
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
