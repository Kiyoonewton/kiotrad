<?php

namespace App\Infrastructure\Provider;

use Illuminate\Support\Facades\DB;

class LocalMirrorStatus
{
    public function view(): ?array
    {
        if (! config('kiotrad.local_data_mirror')) {
            return null;
        }
        if (! str_starts_with(config('database.connections.mongodb.database'), 'kiotrad_local_')) {
            throw new \RuntimeException('Local mirror requires an isolated local database.');
        }
        $row = DB::connection('mongodb')->getDatabase()->local_sync_status->findOne(['_id' => 'source']);

        return $row ? ['database' => config('database.connections.mongodb.database'), 'completed_at' => $row['completed_at'], 'captures' => $row['captures'], 'mode' => $row['mode']] : null;
    }
}
