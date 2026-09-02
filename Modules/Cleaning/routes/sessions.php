<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Cleaning\Http\Controllers\API\CleaningBookingScheduleController;
use Modules\Cleaning\Http\Controllers\API\CleaningBookingSessionAcceptanceController;
use Modules\Cleaning\Http\Controllers\API\CleaningBookingSessionLifecycleController;

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

        Route::post(
            'cleaning-bookings/{cleaning_booking}/sessions/{cleaning_booking_session}/start-travel',
            [CleaningBookingSessionLifecycleController::class, 'startTravel'],
        )->name('cleaning-bookings.sessions.start-travel');

        Route::post(
            'cleaning-bookings/{cleaning_booking}/sessions/{cleaning_booking_session}/arrive',
            [CleaningBookingSessionLifecycleController::class, 'arrive'],
        )->name('cleaning-bookings.sessions.arrive');

        Route::get(
            'cleaning-bookings/{cleaning_booking}/sessions/{cleaning_booking_session}/security-code',
            [CleaningBookingSessionLifecycleController::class, 'securityCode'],
        )->name('cleaning-bookings.sessions.security-code');

        Route::post(
            'cleaning-bookings/{cleaning_booking}/sessions/{cleaning_booking_session}/start-verification/confirm',
            [CleaningBookingSessionLifecycleController::class, 'confirmStartVerification'],
        )->name('cleaning-bookings.sessions.start-verification.confirm');

        Route::post(
            'cleaning-bookings/{cleaning_booking}/sessions/{cleaning_booking_session}/start-work',
            [CleaningBookingSessionLifecycleController::class, 'startWork'],
        )->name('cleaning-bookings.sessions.start-work');

        Route::post(
            'cleaning-bookings/{cleaning_booking}/sessions/{cleaning_booking_session}/complete',
            [CleaningBookingSessionLifecycleController::class, 'complete'],
        )->name('cleaning-bookings.sessions.complete');

        Route::post(
            'cleaning-bookings/{cleaning_booking}/sessions/{cleaning_booking_session}/completion/confirm',
            [CleaningBookingSessionLifecycleController::class, 'confirmCompletion'],
        )->name('cleaning-bookings.sessions.completion.confirm');
    });
