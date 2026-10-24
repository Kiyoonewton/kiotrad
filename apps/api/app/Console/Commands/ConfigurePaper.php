<?php

namespace App\Console\Commands;

use App\Domain\Paper\PaperLedger;
use App\Infrastructure\Paper\PaperStore;
use Illuminate\Console\Command;
use Throwable;

class ConfigurePaper extends Command
{
    protected $signature = 'paper:configure {capital : Whole naira, paper money only} {--target= : Optional whole-naira target}';

    protected $description = 'Initialize the shared paper bankroll once; never resets existing trades';

    public function handle(PaperLedger $ledger, PaperStore $store): int
    {
        $capital = (string) $this->argument('capital');
        $target = $this->option('target');
        if (! preg_match('/^[1-9]\d{0,7}$/D', $capital) || ($target !== null && ! preg_match('/^[1-9]\d{0,7}$/D', $target))) {
            $this->error('Use positive whole-naira amounts without separators.');

            return self::FAILURE;
        }
        try {
            $created = $store->create($ledger->open((int) $capital * 100, $target === null ? null : (int) $target * 100, now('UTC')->toIso8601String()));
            if (! $created) {
                $this->error('Paper bankroll already exists. No funds or history were changed.');

                return self::FAILURE;
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Paper configuration failed. Check amount limits and database availability.');

            return self::FAILURE;
        }
        $this->info('Paper bankroll configured. Start paper:watch to simulate eligible decisions. No real bets are placed.');

        return self::SUCCESS;
    }
}
