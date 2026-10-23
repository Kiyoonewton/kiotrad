<?php

use App\Http\Controllers\OverviewController;
use App\Http\Controllers\ReadinessController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/overview', OverviewController::class);
Route::get('/v1/ready', ReadinessController::class);
