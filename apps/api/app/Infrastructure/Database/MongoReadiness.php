<?php

namespace App\Infrastructure\Database;

use Illuminate\Support\Facades\DB;

class MongoReadiness
{
    public function check(): void
    {
        DB::connection('mongodb')->getMongoClient()
            ->selectDatabase(config('database.connections.mongodb.database'))
            ->command(['ping' => 1])->toArray();
    }
}
