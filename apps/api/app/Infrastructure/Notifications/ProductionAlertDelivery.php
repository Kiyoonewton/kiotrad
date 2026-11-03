<?php

namespace App\Infrastructure\Notifications;

final class ProductionAlertDelivery
{
    public static function allowed(): bool
    {
        $database = (string) config('database.connections.mongodb.database');

        return app()->environment('production') && ! config('kiotrad.local_data_mirror')
            && $database !== '' && ! str_starts_with($database, 'kiotrad_local');
    }
}
