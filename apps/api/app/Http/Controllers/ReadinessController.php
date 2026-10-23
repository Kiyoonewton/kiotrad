<?php

namespace App\Http\Controllers;

use App\Infrastructure\Database\MongoReadiness;
use Illuminate\Http\JsonResponse;
use Throwable;

final class ReadinessController extends Controller
{
    public function __invoke(MongoReadiness $database): JsonResponse
    {
        try {
            $database->check();

            return response()->json(['status' => 'ready', 'database' => 'available']);
        } catch (Throwable) {
            // Do not expose the DSN, credentials or driver exception to clients.
            return response()->json(['status' => 'unavailable', 'database' => 'unavailable'], 503);
        }
    }
}
