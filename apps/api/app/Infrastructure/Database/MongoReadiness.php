<?php

namespace App\Infrastructure\Database;

use Illuminate\Support\Facades\DB;

class MongoReadiness
{
    public function check(): void
    {
        DB::connection('mongodb')->getDatabase()->command(['ping' => 1])->toArray();
    }
}
