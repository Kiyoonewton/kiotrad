<?php

use App\Infrastructure\Notifications\AlertStakeEvidence;
use App\Infrastructure\Notifications\AlertStakePlanner;
use App\Infrastructure\Notifications\QualificationAlertSettings;
use App\Infrastructure\Notifications\QualificationEmailSettings;
use App\Infrastructure\Notifications\QualificationEmailStore;
use App\Infrastructure\Notifications\QualificationSlackSettings;
use App\Infrastructure\Notifications\QualificationSlackStore;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing')) {
    throw new RuntimeException('Alert stake smoke requires APP_ENV=testing.');
}
config(['database.connections.mongodb.database' => 'kiotrad_alert_stake_smoke']);
DB::purge('mongodb');
$db = DB::connection('mongodb')->getDatabase();
$assert = function (bool $ok, string $reason): void {
    if (! $ok) {
        throw new RuntimeException($reason);
    }
};
try {
    $db->drop();
    $emailSettings = new QualificationEmailSettings;
    $slackSettings = new QualificationSlackSettings;
    $db->qualification_email_settings->insertOne(['_id' => 'current', 'capital' => 50000]);
    $db->qualification_slack_settings->insertOne(['_id' => 'current', 'capital' => 100000]);
    $assert($emailSettings->read()['capital'] === 50000 && $slackSettings->read()['capital'] === 100000, 'Legacy settings changed before shared save.');
    $shared = new QualificationAlertSettings;
    $assert($shared->view()['needs_review'], 'Conflicting capital was silently migrated.');
    $shared->save([...$emailSettings->defaults(), 'capital' => 150000, 'email_enabled' => false, 'slack_enabled' => true]);
    $assert($emailSettings->read()['capital'] === 150000 && $slackSettings->read()['capital'] === 150000, 'Workers did not share saved capital.');
    $assert(! $emailSettings->read()['enabled'] && $slackSettings->read()['enabled'], 'Channel switches were not independent.');
    $emailSettings->save([...$emailSettings->defaults(), 'capital' => 125000]);
    $assert($slackSettings->read()['capital'] === 125000, 'Legacy client update diverged from shared settings.');
    $fixture = ['provider_match_id' => 'vf:1', 'matchday' => 1, 'home_team_uid' => 'h', 'away_team_uid' => 'a',
        'home_team' => 'Synthetic Home', 'away_team' => 'Synthetic Away', 'scheduled_start_at' => '2026-11-03T10:03:00+00:00',
        'status' => 'scheduled_or_unconfirmed'];
    $next = [...$fixture, 'provider_match_id' => 'vf:2', 'matchday' => 2, 'scheduled_start_at' => '2026-11-03T10:06:40+00:00'];
    $asOf = '2026-11-03T10:00:00+00:00';
    $db->provider_captures->insertMany([
        ['_id' => 'schedule', 'competition' => 'virtual-euro', 'season_id' => '200', 'observed_at' => '2026-11-03T09:59:50+00:00', 'matches' => [$fixture, $next]],
        ['_id' => 'history', 'observed_at' => '2026-11-02T10:00:00+00:00'],
        ['_id' => 'future-source', 'observed_at' => '2026-11-04T10:00:00+00:00'],
        ['_id' => 'future-schedule', 'competition' => 'virtual-euro', 'season_id' => '200', 'observed_at' => '2026-11-04T10:00:00+00:00', 'matches' => []],
    ]);
    $minimum = ['capture_id' => 'history', 'observed_at' => '2026-11-02T09:59:00+00:00', 'odds_scaled' => 20000, 'season_id' => '100'];
    $db->fixture_odds_season_minima->insertMany([
        ['_id' => 'historical-pair', 'competition' => 'virtual-euro', 'season_id' => '100', 'season_start_at' => '2026-11-02T09:00:00+00:00',
            'home_team_uid' => 'h', 'away_team_uid' => 'a', 'minima' => ['H' => $minimum]],
        ['_id' => 'not-yet-known', 'competition' => 'virtual-euro', 'season_id' => '99', 'season_start_at' => '2026-11-01T09:00:00+00:00',
            'home_team_uid' => 'h', 'away_team_uid' => 'a', 'minima' => ['H' => [...$minimum, 'odds_scaled' => 11000, 'capture_id' => 'future-source']]],
        ['_id' => 'current-season', 'competition' => 'virtual-euro', 'season_id' => '200', 'season_start_at' => '2026-11-01T08:00:00+00:00',
            'home_team_uid' => 'h', 'away_team_uid' => 'a', 'minima' => ['H' => [...$minimum, 'odds_scaled' => 12000]]],
    ]);
    $candidate = ['competition' => 'virtual-euro', 'season_id' => '200', 'team_uid' => 'h', 'strategy_id' => 'champion-next-season', 'next_match' => $fixture];
    $evidence = new AlertStakeEvidence;
    $window = $evidence->window($candidate, 2, $asOf);
    $assert(count($window) === 2 && $window[0]['estimated_odds_scaled'] === 20000, 'Future source or current-season odds leaked into baseline.');
    $price = ['provider_match_id' => 'vf:1', 'pre_match_verified' => true, 'captured_at' => '2026-11-03T09:59:50+00:00',
        'stored_at' => '2026-11-03T09:59:51+00:00', 'capture_id' => 'current-price', 'prices' => ['H' => '2.20']];
    $plan = (new AlertStakePlanner($evidence))->make($candidate, ['fixture' => $fixture, 'price' => $price],
        ['capital' => 100000, 'next_season_attempts' => 2, 'allowance' => 2], $asOf);
    $assert($plan['status'] === 'estimated' && $plan['allowance_minor'] <= 10000000, 'Saved-capital plan exceeded allowance.');
    $alert = ['id' => 'synthetic-run', 'deadline' => '2026-11-03T10:02:50+00:00', 'details' => [], 'stake_plan' => $plan];
    $store = new QualificationEmailStore;
    $assert($store->claim($alert) && ! $store->claim($alert), 'Email delivery identity was not insert-only.');
    $assert((new QualificationSlackStore)->claim($alert), 'Email incorrectly suppressed Slack.');
    $saved = $db->qualification_email_alerts->findOne(['_id' => 'synthetic-run']);
    $assert($saved['stake_plan']['capital_minor'] === 10000000, 'Pinned capital/plan not preserved.');
    $db->fixture_odds_season_minima->deleteOne(['_id' => 'historical-pair']);
    $assert($evidence->window($candidate, 2, $asOf) === [], 'Unknown/future history fabricated a fallback.');
    echo "Alert stake smoke passed: causal source cutoff, configured budget, pinned plan and channel deduplication.\n";
} finally {
    $db->drop();
}
