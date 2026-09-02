<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Cleaning\Http\Controllers\API\CleaningBookingScheduleController;
use Modules\Cleaning\Http\Controllers\API\CleaningBookingSessionAcceptanceController;

Route::prefix('v1')
    ->middleware(['auth:sanctum'])
    ->group(function (): void {
        Route::get(
            'cleaning-bookings/{cleaning_booking}/schedule',
            CleaningBookingScheduleController::class,
        )->name('cleaning-bookings.schedule');

        Route::post(
            'cleaning-bookings/{cleaning_booking}/sessions/accept-all',
            [CleaningBookingSessionAcceptanceController::class, 'acceptAll'],
        )->name('cleaning-bookings.sessions.accept-all');

        Route::post(
            'cleaning-bookings/{cleaning_booking}/sessions/accept-selected',
            [CleaningBookingSessionAcceptanceController::class, 'acceptSelected'],
        )->name('cleaning-bookings.sessions.accept-selected');
    });
