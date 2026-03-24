<?php

declare(strict_types=1);

use App\Http\Controllers\API\UserNotificationController;
use Illuminate\Support\Facades\Route;
use Modules\Cleaning\Http\Controllers\API\CleaningBillingPolicyController;
use Modules\Cleaning\Http\Controllers\API\CleaningBookingController;
use Modules\Cleaning\Http\Controllers\API\CleaningServiceController;
use Modules\Cleaning\Http\Controllers\API\CleaningTimeWarningController;
use Modules\Cleaning\Http\Controllers\API\DashboardOverviewController;
use Modules\Cleaning\Http\Controllers\API\EventBookingController;
use Modules\Cleaning\Http\Controllers\API\GeographicCoverageController;
use Modules\Cleaning\Http\Controllers\API\ServicePricingController;
use Modules\Cleaning\Http\Controllers\API\WorkerAccountStatusController;
use Modules\Cleaning\Http\Controllers\API\WorkerHomepageController;
use Modules\Cleaning\Http\Controllers\API\WorkerStatisticsController;
use Modules\Cleaning\Http\Controllers\API\WorkerTransactionsController;
use Modules\Cleaning\Http\Controllers\API\WorkerWorkAreasController;
use Modules\Cleaning\Http\Controllers\API\WorkerWorkingHoursController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('cleaning/dashboard/overview', DashboardOverviewController::class);
    Route::get('cleaning/worker/homepage', WorkerHomepageController::class);
    Route::get('cleaning/worker/statistics', WorkerStatisticsController::class);
    Route::get('cleaning/worker/profile', Modules\Cleaning\Http\Controllers\API\WorkerProfileController::class);
    Route::get('cleaning/worker/working-hours', [WorkerWorkingHoursController::class, 'show']);
    Route::put('cleaning/worker/working-hours', [WorkerWorkingHoursController::class, 'update']);
    Route::prefix('cleaning/worker/account')->group(function (): void {
        Route::get('profile', Modules\Cleaning\Http\Controllers\API\WorkerProfileController::class);
        Route::get('work-areas', [WorkerWorkAreasController::class, 'show']);
        Route::put('work-areas', [WorkerWorkAreasController::class, 'update']);
        Route::get('working-hours', [WorkerWorkingHoursController::class, 'show']);
        Route::put('working-hours', [WorkerWorkingHoursController::class, 'update']);
        Route::get('notifications', [UserNotificationController::class, 'index']);
        Route::patch('notifications/{id}/read', [UserNotificationController::class, 'markAsRead']);
        Route::get('transactions', WorkerTransactionsController::class);
        Route::get('status', [WorkerAccountStatusController::class, 'show']);
        Route::patch('status', [WorkerAccountStatusController::class, 'update']);
    });
    Route::get('cleaning/analytics/geographic-coverage', GeographicCoverageController::class);
    Route::post('cleaning-bookings/{cleaning_booking}/accept', [CleaningBookingController::class, 'accept'])->name('cleaning-bookings.accept');
    Route::post('cleaning-bookings/{cleaning_booking}/reject', [CleaningBookingController::class, 'reject'])->name('cleaning-bookings.reject');
    Route::get('cleaning-bookings/{cleaning_booking}/security-code', [CleaningBookingController::class, 'securityCode'])->name('cleaning-bookings.security-code');
    Route::post('cleaning-bookings/{cleaning_booking}/start-travel', [CleaningBookingController::class, 'startTravel'])->name('cleaning-bookings.start-travel');
    Route::post('cleaning-bookings/{cleaning_booking}/location', [CleaningBookingController::class, 'updateLocation'])->name('cleaning-bookings.location');
    Route::post('cleaning-bookings/{cleaning_booking}/arrive', [CleaningBookingController::class, 'arrive'])->name('cleaning-bookings.arrive');
    Route::post('cleaning-bookings/{cleaning_booking}/start-work', [CleaningBookingController::class, 'startWork'])->name('cleaning-bookings.start-work');
    Route::post('cleaning-bookings/{cleaning_booking}/complete', [CleaningBookingController::class, 'complete'])->name('cleaning-bookings.complete');
    Route::post('cleaning-bookings/{cleaning_booking}/cancel', [CleaningBookingController::class, 'cancel'])->name('cleaning-bookings.cancel');
    Route::apiResource('cleaning-bookings', CleaningBookingController::class);
    Route::apiResource('event-bookings', EventBookingController::class);
    Route::post('cleaning-time-warnings/{cleaning_time_warning}/accept', [CleaningTimeWarningController::class, 'accept'])->name('cleaning-time-warnings.accept');
    Route::post('cleaning-time-warnings/{cleaning_time_warning}/reject', [CleaningTimeWarningController::class, 'reject'])->name('cleaning-time-warnings.reject');
    Route::apiResource('cleaning-time-warnings', CleaningTimeWarningController::class)->only(['index', 'show']);
    Route::apiResource('cleaning-services', CleaningServiceController::class);
    Route::apiResource('cleaning-services.pricing', ServicePricingController::class)->scoped();
    Route::apiResource('cleaning-billing-policies', CleaningBillingPolicyController::class);
});
