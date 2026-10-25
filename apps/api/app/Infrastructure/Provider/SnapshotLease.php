<?php

namespace App\Infrastructure\Provider;

use Illuminate\Contracts\Cache\Lock;
use Throwable;

final class SnapshotLease
{
    private static ?Lock $active = null;

    private static bool $registered = false;

    private static string $reserve = '';

    public static function protect(Lock $lock): void
    {
        self::$active = $lock;
        if (! self::$registered) {
            self::$reserve = str_repeat('x', 131072);
            register_shutdown_function(static function (): void {
                // Free reserved memory before Redis cleanup after a PHP memory fatal.
                self::$reserve = '';
                try {
                    self::release();
                } catch (Throwable) {
                    // SIGKILL/Redis outages still rely on the bounded lease expiry.
                }
            });
            self::$registered = true;
        }
    }

    public static function release(): void
    {
        $lock = self::$active;
        self::$active = null;
        $lock?->release();
    }
}
