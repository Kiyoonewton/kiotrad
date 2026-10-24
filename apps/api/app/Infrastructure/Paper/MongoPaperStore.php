<?php

namespace App\Infrastructure\Paper;

use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\UTCDateTime;

final class MongoPaperStore implements PaperStore
{
    private function collection()
    {
        return DB::connection('mongodb')->getDatabase()->selectCollection('paper_portfolios');
    }

    public function read(): ?array
    {
        $row = $this->collection()->findOne(['_id' => 'main'], ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);
        if ($row !== null) {
            unset($row['_id']);
        }

        return $row;
    }

    public function create(array $state): bool
    {
        DB::connection('mongodb')->getDatabase()->provider_captures->createIndex(['competition' => 1, 'season_id' => 1, 'observed_at' => -1]);

        return $this->collection()->updateOne(['_id' => 'main'], ['$setOnInsert' => $state], ['upsert' => true])->getUpsertedCount() === 1;
    }

    public function replace(int $revision, array $state): bool
    {
        $before = $this->read();
        if ($before === null || $before['revision'] !== $revision) {
            return false;
        }
        foreach (['decisions', 'runs', 'capital_history'] as $journal) {
            if (array_slice($state[$journal], 0, count($before[$journal])) !== $before[$journal]) {
                throw new DomainException('Paper journal entries cannot be rewritten.');
            }
        }
        foreach (['starting_balance_minor', 'target_balance_minor', 'created_at', 'policy_version', 'currency'] as $fixed) {
            if ($state[$fixed] !== $before[$fixed]) {
                throw new DomainException('Paper configuration is immutable.');
            }
        }
        $this->assertIntegrity($before, $state);
        $state['revision'] = $revision + 1;

        $filter = ['_id' => 'main', 'revision' => $revision];
        $last = $state['decisions'] === [] ? null : $state['decisions'][array_key_last($state['decisions'])];
        if ($last !== null && count($state['decisions']) > count($before['decisions'])) {
            // New reservations must also beat kickoff on the database server clock.
            $kickoff = new DateTimeImmutable($last['scheduled_start_at']);
            $priceExpiry = (new DateTimeImmutable($last['price_observed_at']))->modify('+300 seconds');
            $filter['$expr'] = ['$lt' => ['$$NOW', new UTCDateTime(min($kickoff, $priceExpiry))]];
        }

        return $this->collection()->updateOne($filter, ['$set' => $state])->getModifiedCount() === 1;
    }

    private function assertIntegrity(array $before, array $state): void
    {
        $realized = array_sum(array_column($state['runs'], 'net_minor')) + ($state['active_run']['net_minor'] ?? 0);
        $active = $state['active_run'];
        $last = $active && $active['attempts'] !== [] ? $active['attempts'][array_key_last($active['attempts'])] : null;
        $reserved = $last && $last['status'] === 'pending' ? $last['stake_minor'] : 0;
        if ($state['cash_minor'] < 0 || $state['committed_minor'] !== $reserved
            || $state['cash_minor'] + $reserved !== $state['starting_balance_minor'] + $realized) {
            throw new DomainException('Paper cash, reservations and settlements do not reconcile.');
        }
        $previousRun = $before['active_run'];
        if ($previousRun === null) {
            return;
        }
        $afterRun = ($active['id'] ?? null) === $previousRun['id'] ? $active : null;
        foreach ($state['runs'] as $run) {
            if ($run['id'] === $previousRun['id']) {
                $afterRun = $run;
            }
        }
        if ($afterRun === null) {
            throw new DomainException('An active paper run cannot be discarded.');
        }
        foreach ($previousRun['attempts'] as $index => $attempt) {
            $sealed = $attempt;
            if ($attempt['status'] === 'pending') {
                unset($sealed['status']);
            }
            if (array_intersect_key($afterRun['attempts'][$index] ?? [], $sealed) !== $sealed) {
                throw new DomainException('Recorded attempts and settlements cannot be rewritten.');
            }
        }
    }
}
