<?php

declare(strict_types=1);

use App\Http\Controllers\API\RegisterFcmTokenController;
use App\Http\Controllers\API\UserNotificationController;
use Illuminate\Support\Facades\Route;
use Modules\Cleaning\Http\Controllers\API\CleaningBillingPolicyController;
use Modules\Cleaning\Http\Controllers\API\CleaningBookingController;
use Modules\Cleaning\Http\Controllers\API\CleaningBookingDeliveryFeeController;
use Modules\Cleaning\Http\Controllers\API\CleaningNeighborhoodController;
use Modules\Cleaning\Http\Controllers\API\CleaningServiceController;
use Modules\Cleaning\Http\Controllers\API\CleaningTimeWarningController;
use Modules\Cleaning\Http\Controllers\API\DashboardOverviewController;
use Modules\Cleaning\Http\Controllers\API\DepositManagementController;
use Modules\Cleaning\Http\Controllers\API\EventBookingController;
use Modules\Cleaning\Http\Controllers\API\GeographicCoverageController;
use Modules\Cleaning\Http\Controllers\API\ServicePricingController;
use Modules\Cleaning\Http\Controllers\API\WorkerAccountProfileController;
use Modules\Cleaning\Http\Controllers\API\WorkerAccountStatusController;
use Modules\Cleaning\Http\Controllers\API\WorkerDepositController;
use Modules\Cleaning\Http\Controllers\API\WorkerDetailsController;
use Modules\Cleaning\Http\Controllers\API\WorkerHomepageController;
use Modules\Cleaning\Http\Controllers\API\WorkerReviewController;
use Modules\Cleaning\Http\Controllers\API\WorkerStatisticsController;
use Modules\Cleaning\Http\Controllers\API\WorkerTransactionsController;
use Modules\Cleaning\Http\Controllers\API\WorkerWorkingHoursController;
use Modules\Cleaning\Http\Controllers\API\WorkerWorkAreasController;

Route::prefix('v1')->group(function () {
    // Public endpoints - no auth required
    Route::apiResource('cleaning-services', CleaningServiceController::class)->only(['index', 'show']);
    Route::apiResource('cleaning-services.pricing', ServicePricingController::class)->only(['index', 'show'])->scoped();
    Route::apiResource('cleaning-billing-policies', CleaningBillingPolicyController::class)->only(['index', 'show']);

    // Protected endpoints - auth required
    Route::middleware(['auth:sanctum'])->group(function (): void {
        Route::get('cleaning/neighborhoods', [CleaningNeighborhoodController::class, 'index']);
        Route::post('cleaning/neighborhoods/match', [CleaningNeighborhoodController::class, 'match']);

        // Worker dashboard and profile endpoints
        Route::get('cleaning/dashboard/overview', DashboardOverviewController::class);
        Route::get('cleaning/worker/homepage', WorkerHomepageController::class);
        Route::get('cleaning/worker/statistics', WorkerStatisticsController::class);
        Route::get('cleaning/worker/reviews', [WorkerReviewController::class, 'index']);
        Route::get('worker/{worker}', WorkerDetailsController::class);
        Route::get('cleaning/worker/profile', Modules\Cleaning\Http\Controllers\API\WorkerProfileController::class);
        Route::get('cleaning/worker/working-hours', [WorkerWorkingHoursController::class, 'show']);
        Route::put('cleaning/worker/working-hours', [WorkerWorkingHoursController::class, 'update']);
        Route::prefix('cleaning/worker/account')->group(function (): void {
            Route::get('profile', Modules\Cleaning\Http\Controllers\API\WorkerProfileController::class);
            Route::put('profile', [WorkerAccountProfileController::class, 'update']);
            Route::put('password', [WorkerAccountProfileController::class, 'updatePassword']);
            Route::get('work-areas', [WorkerWorkAreasController::class, 'show']);
            Route::put('work-areas', [WorkerWorkAreasController::class, 'update']);
            Route::get('working-hours', [WorkerWorkingHoursController::class, 'show']);
            Route::put('working-hours', [WorkerWorkingHoursController::class, 'update']);
            Route::get('notifications', [UserNotificationController::class, 'index']);
            Route::patch('notifications/read-all', [UserNotificationController::class, 'markAllAsRead']);
            Route::patch('notifications/{id}/read', [UserNotificationController::class, 'markAsRead']);
            Route::put('notifications/token', RegisterFcmTokenController::class);
            Route::get('transactions', WorkerTransactionsController::class);
            Route::get('status', [WorkerAccountStatusController::class, 'show']);
            Route::patch('status', [WorkerAccountStatusController::class, 'update']);
            Route::get('deposit', [WorkerDepositController::class, 'getStatus']);
            Route::get('deposit/transactions', [WorkerDepositController::class, 'getTransactions']);
        });

        // Analytics endpoints
        Route::get('cleaning/analytics/geographic-coverage', GeographicCoverageController::class);

        // Resource management endpoints (admin)
        Route::apiResource('cleaning-services', CleaningServiceController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('cleaning-services.pricing', ServicePricingController::class)->only(['store', 'update', 'destroy'])->scoped();
        Route::apiResource('cleaning-billing-policies', CleaningBillingPolicyController::class)->only(['store', 'update', 'destroy']);

        // Deposit management endpoints (admin)
        Route::prefix('admin/cleaning/deposits')->group(function (): void {
            Route::get('settings', [DepositManagementController::class, 'getSettings']);
            Route::put('settings', [DepositManagementController::class, 'updateSettings']);
            Route::post('{worker}/deposit', [DepositManagementController::class, 'recordDeposit']);
            Route::post('{worker}/withdraw', [DepositManagementController::class, 'recordWithdrawal']);
            Route::get('{worker}/transactions', [DepositManagementController::class, 'getWorkerTransactions']);
        });

        // Booking endpoints (ordering)
        Route::post('cleaning-bookings/{cleaning_booking}/delivery-fee', CleaningBookingDeliveryFeeController::class)->name('cleaning-bookings.delivery-fee');
        Route::post('cleaning-bookings/{cleaning_booking}/accept', [CleaningBookingController::class, 'accept'])->name('cleaning-bookings.accept');
        Route::post('cleaning-bookings/{cleaning_booking}/rooms/claim', [CleaningBookingController::class, 'claimRooms'])->name('cleaning-bookings.rooms.claim');
        Route::post('cleaning-bookings/{cleaning_booking}/reject', [CleaningBookingController::class, 'reject'])->name('cleaning-bookings.reject');
        Route::get('cleaning-bookings/{cleaning_booking}/security-code', [CleaningBookingController::class, 'securityCode'])->name('cleaning-bookings.security-code');
        Route::post('cleaning-bookings/{cleaning_booking}/sos', [CleaningBookingController::class, 'sos'])->name('cleaning-bookings.sos');
        Route::post('cleaning-bookings/{cleaning_booking}/start-travel', [CleaningBookingController::class, 'startTravel'])->name('cleaning-bookings.start-travel');
        Route::post('cleaning-bookings/{cleaning_booking}/location', [CleaningBookingController::class, 'updateLocation'])->name('cleaning-bookings.location');
        Route::post('cleaning-bookings/{cleaning_booking}/arrive', [CleaningBookingController::class, 'arrive'])->name('cleaning-bookings.arrive');
        Route::post('cleaning-bookings/{cleaning_booking}/start-work', [CleaningBookingController::class, 'startWork'])->name('cleaning-bookings.start-work');
        Route::post('cleaning-bookings/{cleaning_booking}/complete', [CleaningBookingController::class, 'complete'])->name('cleaning-bookings.complete');
        Route::post('cleaning-bookings/{cleaning_booking}/finish', [CleaningBookingDeliveryFeeController::class, 'finish'])->name('cleaning-bookings.finish');
        Route::post('cleaning-bookings/{cleaning_booking}/cancel', [CleaningBookingController::class, 'cancel'])->name('cleaning-bookings.cancel');
        Route::apiResource('cleaning-bookings', CleaningBookingController::class);
        Route::apiResource('event-bookings', EventBookingController::class);

        // Time warning endpoints
        Route::post('cleaning-time-warnings/{cleaning_time_warning}/accept', [CleaningTimeWarningController::class, 'accept'])->name('cleaning-time-warnings.accept');
        Route::post('cleaning-time-warnings/{cleaning_time_warning}/reject', [CleaningTimeWarningController::class, 'reject'])->name('cleaning-time-warnings.reject');
        Route::apiResource('cleaning-time-warnings', CleaningTimeWarningController::class)->only(['index', 'show']);
    });
});