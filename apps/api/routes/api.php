<?php

use App\Http\Controllers\OverviewController;
use App\Http\Controllers\ReadinessController;
use App\Http\Controllers\SimulationHistoryController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/overview', OverviewController::class);
Route::get('/v1/ready', ReadinessController::class);
Route::get('/v1/simulations', SimulationHistoryController::class);
