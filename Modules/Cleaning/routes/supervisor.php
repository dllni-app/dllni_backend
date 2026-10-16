<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Cleaning\Http\Controllers\API\SupervisorAppController;
use Modules\Cleaning\Http\Controllers\API\SupervisorLifecycleController;
use Modules\Cleaning\Http\Controllers\API\SupervisorCustomerController;
use Modules\Cleaning\Http\Controllers\API\SupervisorOperationsController;

Route::prefix('v1')->group(function (): void {
    Route::post('supervisor/auth/login', [SupervisorAppController::class, 'login'])
        ->middleware('throttle:6,1');
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::prefix('supervisor')->group(function (): void {
            Route::post('auth/logout', [SupervisorAppController::class, 'logout']);
            Route::get('me', [SupervisorAppController::class, 'me']);
            Route::get('home', [SupervisorAppController::class, 'home']);
            Route::get('bookings', [SupervisorAppController::class, 'index']);
            Route::get('schedule', [SupervisorAppController::class, 'schedule']);
            Route::get('bookings/{booking}', [SupervisorAppController::class, 'show'])->whereNumber('booking');
            Route::get('bookings/{booking}/attendance', [SupervisorAppController::class, 'attendance']);
            Route::get('bookings/{booking}/timeline', [SupervisorAppController::class, 'timeline']);
            Route::post('bookings/{booking}/verification/request', [SupervisorLifecycleController::class, 'verificationRequest'])
                ->middleware('throttle:5,1');
            Route::post('bookings/{booking}/verification/confirm', [SupervisorLifecycleController::class, 'verificationConfirm'])
                ->middleware('throttle:10,1');
            Route::get('bookings/{booking}/verification/status', [SupervisorLifecycleController::class, 'verificationStatus']);
            Route::post('bookings/{booking}/start', [SupervisorLifecycleController::class, 'start']);
            Route::post('bookings/{booking}/handover-request', [SupervisorLifecycleController::class, 'handoverRequest']);
            Route::get('bookings/{booking}/handover-status', [SupervisorLifecycleController::class, 'handoverStatus']);
            Route::post('bookings/{booking}/attendance-incidents', [SupervisorAppController::class, 'createIncident']);
            Route::post('bookings/{booking}/incidents', [SupervisorAppController::class, 'createIncident']);
            Route::get('bookings/{booking}/incidents', [SupervisorAppController::class, 'incidents']);
            Route::post('bookings/{booking}/replacement-requests', [SupervisorAppController::class, 'replacementRequest']);
            Route::post('bookings/{booking}/extensions', [SupervisorLifecycleController::class, 'extensionRequest']);
            Route::get('bookings/{booking}/extensions', [SupervisorLifecycleController::class, 'extensions']);
            Route::post('bookings/{booking}/report', [SupervisorLifecycleController::class, 'report']);
            Route::get('bookings/{booking}/reports', [SupervisorLifecycleController::class, 'reports']);
            Route::post('bookings/{booking}/media', [SupervisorLifecycleController::class, 'mediaUpload']);
            Route::get('bookings/{booking}/media', [SupervisorLifecycleController::class, 'mediaList']);
            Route::get('bookings/{booking}/media/{media}', [SupervisorLifecycleController::class, 'mediaDownload']);
            Route::delete('bookings/{booking}/media/{media}', [SupervisorLifecycleController::class, 'mediaDelete']);
        });

        Route::prefix('user/cleaning/orders/{booking}/supervised')->group(function (): void {
            Route::get('verification/code', [SupervisorCustomerController::class, 'verificationCode']);
            Route::post('handover/decision', [SupervisorCustomerController::class, 'handoverDecision']);
            Route::post('extensions/{extension}/decision', [SupervisorCustomerController::class, 'extensionDecision']);
        });

        Route::prefix('admin/cleaning/supervised')->group(function (): void {
            Route::post('bookings/{booking}/supervisor', [SupervisorOperationsController::class, 'assign']);
            Route::post('extensions/{extension}/decision', [SupervisorOperationsController::class, 'decideExtension']);
            Route::post('bookings/{booking}/objection/resolve', [SupervisorOperationsController::class, 'resolveObjection']);
            Route::post('replacements/{replacement}/decision', [SupervisorOperationsController::class, 'decideReplacement']);
        });
    });
});
