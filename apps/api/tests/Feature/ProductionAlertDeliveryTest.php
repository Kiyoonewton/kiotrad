<?php

namespace Tests\Feature;

use App\Infrastructure\Notifications\AlertStakePlanner;
use App\Infrastructure\Notifications\ProductionAlertDelivery;
use App\Infrastructure\Notifications\QualificationEmailPolicy;
use App\Infrastructure\Notifications\QualificationEmailSettings;
use App\Infrastructure\Notifications\QualificationEmailStore;
use App\Infrastructure\Notifications\QualificationEmailWorker;
use App\Infrastructure\Notifications\QualificationSlackSettings;
use App\Infrastructure\Notifications\QualificationSlackStore;
use App\Infrastructure\Notifications\QualificationSlackWorker;
use App\Infrastructure\Notifications\SlackTransport;
use App\Infrastructure\Paper\PaperMarket;
use App\Infrastructure\Provider\ChampionMonitoring;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class ProductionAlertDeliveryTest extends TestCase
{
    public function test_environment_and_mirror_gates_block_both_channels_before_any_storage_or_transport(): void
    {
        Mail::fake();
        foreach ([['local', false, 'kiotrad'], ['testing', false, 'kiotrad'], ['production', true, 'kiotrad'], ['production', false, 'kiotrad_local_snapshot']] as [$env, $mirror, $db]) {
            $this->app->instance('env', $env);
            config(['kiotrad.local_data_mirror' => $mirror, 'database.connections.mongodb.database' => $db]);
            $this->assertFalse(ProductionAlertDelivery::allowed());
            $monitor = Mockery::mock(ChampionMonitoring::class);
            $monitor->shouldNotReceive('forEmail');
            $market = Mockery::mock(PaperMarket::class);
            $planner = Mockery::mock(AlertStakePlanner::class);
            $planner->shouldNotReceive('make');
            $email = new QualificationEmailWorker($monitor, $market, new QualificationEmailPolicy,
                Mockery::mock(QualificationEmailStore::class), Mockery::mock(QualificationEmailSettings::class), $planner);
            $transport = Mockery::mock(SlackTransport::class);
            $transport->shouldNotReceive('send');
            $slack = new QualificationSlackWorker($monitor, $market, new QualificationEmailPolicy,
                Mockery::mock(QualificationSlackStore::class), Mockery::mock(QualificationSlackSettings::class), $transport, $planner);
            $this->assertSame('production_only', $email->tick());
            $this->assertSame('production_only', $slack->tick());
        }
        Mail::assertNothingSent();
        $this->app->instance('env', 'production');
        config(['kiotrad.local_data_mirror' => false, 'database.connections.mongodb.database' => 'kiotrad']);
        $this->assertTrue(ProductionAlertDelivery::allowed());
    }
}
