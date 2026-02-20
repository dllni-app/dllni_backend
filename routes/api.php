<?php

declare(strict_types=1);

use App\Http\Controllers\API\DisputeController;
use App\Http\Controllers\API\ServiceAddonController;
use App\Http\Controllers\API\SosAlertController;
use App\Http\Controllers\API\SystemAlertController;
use App\Http\Controllers\API\TravelCostConfigController;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\API\WorkerController;
use Illuminate\Support\Facades\Route;

Route::apiResource('users', UserController::class);

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('workers', WorkerController::class);
    Route::apiResource('disputes', DisputeController::class);
    Route::apiResource('system-alerts', SystemAlertController::class)->only(['index', 'show', 'update']);
    Route::apiResource('sos-alerts', SosAlertController::class)->only(['index', 'show']);
    Route::apiResource('service-addons', ServiceAddonController::class);
    Route::apiResource('travel-cost-configs', TravelCostConfigController::class);
});
