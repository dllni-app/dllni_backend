<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Cleaning\Http\Controllers\API\WorkerComplianceController;

Route::prefix('v1/cleaning/worker/compliance')
    ->middleware('auth:sanctum')
    ->group(function (): void {
        Route::get('config', [WorkerComplianceController::class, 'config']);
        Route::post('session', [WorkerComplianceController::class, 'createSession'])->middleware('throttle:10,1');
        Route::post('samples', [WorkerComplianceController::class, 'storeSamples'])->middleware('throttle:30,1');
        Route::delete('session', [WorkerComplianceController::class, 'revokeSession']);
    });
