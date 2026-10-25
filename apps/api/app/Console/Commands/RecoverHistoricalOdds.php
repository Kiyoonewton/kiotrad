<?php

namespace App\Console\Commands;

use App\Infrastructure\Provider\CollectionStore;
use App\Infrastructure\Provider\OddsNormalizer;
use App\Infrastructure\Provider\SeasonCapture;
use App\Infrastructure\Provider\SportradarClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class RecoverHistoricalOdds extends Command
{
    protected $signature = 'provider:recover-odds {competition} {seasons*} {--from-day=1} {--to-day=30}';

    protected $description = 'Recover archived 1X2 prices for explicit seasons; reruns skip already priced fixtures';

    public function handle(SeasonCapture $capture, SportradarClient $client, OddsNormalizer $normalizer, CollectionStore $store): int
    {
        $competition = $this->argument('competition');
        $seasons = array_unique($this->argument('seasons'));
        $from = filter_var($this->option('from-day'), FILTER_VALIDATE_INT);
        $to = filter_var($this->option('to-day'), FILTER_VALIDATE_INT);
        if (! array_key_exists($competition, config('provider.competitions')) || $from === false || $to === false
            || $from < 1 || $to > 30 || $from > $to
            || array_filter($seasons, fn ($id) => ! ctype_digit($id) || strlen($id) > 20)) {
            $this->error('Use a configured league, numeric season IDs and matchdays 1–30.');

            return self::FAILURE;
        }
        $failed = false;
        foreach ($seasons as $season) {
            try {
                $report = $capture->run($competition, $season, 'historical_odds', [], 0);
                if (! $report['completeness']['results_complete']) {
                    throw new \RuntimeException('Historical recovery requires a complete settled season.');
                }
                $known = [];
                $cursor = DB::connection('mongodb')->getDatabase()->provider_captures->find(
                    ['competition' => $competition, 'season_id' => $season],
                    ['projection' => ['odds_snapshots' => 1], 'batchSize' => 5,
                        'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);
                foreach ($cursor as $saved) {
                    foreach ($saved['odds_snapshots'] ?? [] as $price) {
                        if (count($price['prices'] ?? []) === 3) {
                            $known[$price['provider_match_id']] = true;
                        }
                    }
                }
                foreach (range($from, $to) as $day) {
                    $fixtures = array_values(array_filter($report['matches'], fn ($m) => $m['matchday'] === $day));
                    if (count($fixtures) === 8 && count(array_filter($fixtures, fn ($m) => isset($known[$m['provider_match_id']]))) === 8) {
                        $this->line($season.' day '.$day.': already priced; skipped.');

                        continue;
                    }
                    try {
                        $feed = $client->odds($season, $day);
                        $prices = $normalizer->normalize($feed, $report['matches'], config("provider.competitions.{$competition}.tournament_uid"), $day);
                        if (count($prices) !== 8) {
                            throw new \RuntimeException('Expected eight valid fixture-matched 1X2 prices.');
                        }
                        $prices = array_map(fn ($p) => [...$p, 'pre_match_verified' => false], $prices);
                        $store->save([...$report, 'observed_at' => $feed->receivedAt->toIso8601String(),
                            'odds_snapshots' => $prices, 'odds_matchday' => $day, 'odds_matchdays' => [$day],
                            'odds_coverage' => [['matchday' => $day, 'status' => 'historical_recorded', 'pre_match_count' => 0]],
                            'verified_pre_match_prices' => 0, 'market_observation' => 'historical_prices_observed']);
                        $this->line($season.' day '.$day.': eight archived prices saved.');
                    } catch (Throwable $exception) {
                        report($exception);
                        $this->error($season.' day '.$day.': recovery failed; rerun to retry.');
                        $failed = true;
                    }
                }
            } catch (Throwable $exception) {
                report($exception);
                $this->error($season.': season recovery failed; existing data preserved.');
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
