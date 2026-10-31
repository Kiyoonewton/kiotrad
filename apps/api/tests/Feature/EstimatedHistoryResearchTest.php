<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\SharedCapitalResearch;
use App\Infrastructure\Research\EstimatedHistoryResearch;
use ArrayIterator;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class EstimatedHistoryResearchTest extends TestCase
{
    use EstimatedHistoryFixtures;

    public function test_local_database_evidence_is_read_without_writing_hypothetical_source_records(): void
    {
        config(['kiotrad.local_data_mirror' => true, 'database.connections.mongodb.database' => 'kiotrad_local_test',
            'provider.competitions' => ['virtual-euro' => []]]);
        $rows = $sources = [];
        foreach (['100' => '10:00:00', '200' => '11:52:00', '300' => '13:44:00', '400' => '15:36:00'] as $id => $time) {
            $id = (string) $id;
            $status = in_array($id, ['300', '400'], true) ? 'recorded' : 'estimated_unbounded';
            $row = $this->timingRow($id, '2026-10-17T'.$time.'+00:00', $status);
            $capture = $this->seasonCapture($id, in_array($id, ['200', '300'], true) ? 5 : 1);
            if ($status === 'recorded') {
                foreach ($capture['matches'] as &$match) {
                    $match['scheduled_start_at'] = (new DateTimeImmutable($row['recorded_day1_utc']))
                        ->modify('+'.(($match['matchday'] - 1) * 220).' seconds')->format('Y-m-d\TH:i:sP');
                }
                unset($match);
            }
            $rows['virtual-euro:'.$id] = $row;
            $sources[$id] = $capture;
        }
        $before = $sources;
        $captures = Mockery::mock();
        $captures->shouldReceive('find')->times(4)->andReturnUsing(fn ($filter, $options) => new ArrayIterator([$sources[$filter['season_id']]]));
        $blocks = Mockery::mock();
        $blocks->shouldReceive('find')->times(4)->andReturnUsing(fn () => new ArrayIterator([]));
        $links = Mockery::mock();
        $links->shouldReceive('find')->twice()->andReturnUsing(fn () => new ArrayIterator([
            ['competition' => 'virtual-euro', 'from_season_id' => '300', 'to_season_id' => '400',
                'observed_at' => '2026-10-31T00:00:00+00:00', 'continuity_status' => 'provider_catalog_adjacency'],
        ]));
        $db = new class($captures, $blocks, $links)
        {
            public function __construct(public $provider_captures, public $market_blockages, private $links) {}

            public function selectCollection(string $name)
            {
                return $this->links;
            }
        };
        DB::shouldReceive('connection->getDatabase')->once()->andReturn($db);
        $recorded = $this->mock(SharedCapitalResearch::class);
        $recorded->shouldReceive('prepare')->once()->andReturn(['candidates' => [], 'excluded' => [],
            'as_of' => '2026-11-01T00:00:00+00:00', 'seasons_assessed' => 4]);
        $result = app(EstimatedHistoryResearch::class)->calculate(['sha256' => 'test', 'input_sha256s' => [], 'seasons' => $rows],
            $this->scenarioSettings(), '2026-11-01T00:00:00+00:00', fn () => null);
        $this->assertSame($before, $sources);
        $this->assertCount(4, $result['season_diagnostics']);
        $this->assertCount(4, $result['provenance']['season_capture_sha256s']);
        $ids = array_column($result['with_estimated_history']['runs'], 'id');
        $this->assertContains('virtual-euro:300:champion-next-season', $ids);
        $this->assertContains('virtual-euro:400:champion-next-season', $ids);
    }
}
