<?php

use App\Http\Controllers\OverviewController;
use App\Http\Controllers\QualificationAlertSettingsController;
use App\Http\Controllers\QualificationEmailSettingsController;
use App\Http\Controllers\ReadinessController;
use App\Http\Controllers\ResearchScenarioController;
use App\Http\Controllers\SimulationHistoryController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/overview', OverviewController::class);
Route::get('/v1/ready', ReadinessController::class);
Route::get('/v1/simulations', SimulationHistoryController::class);

Route::get('/v1/research/scenario', ResearchScenarioController::class);

Route::match(['get', 'put'], '/v1/notifications/email-settings', QualificationEmailSettingsController::class);

Route::match(['get', 'put'], '/v1/notifications/slack-settings', QualificationEmailSettingsController::class)->defaults('channel', 'slack');

Route::match(['get', 'put'], '/v1/notifications/alert-settings', QualificationAlertSettingsController::class);
