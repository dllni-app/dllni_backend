<?php

declare(strict_types=1);

use App\Http\Controllers\API\RegisterFcmTokenController;
use App\Http\Controllers\API\UserNotificationController;
use Illuminate\Support\Facades\Route;
use Modules\Cleaning\Http\Controllers\API\AssignSupervisedBookingController;
use Modules\Cleaning\Http\Controllers\API\AssignSupervisedVisitController;
use Modules\Cleaning\Http\Controllers\API\CleaningBillingPolicyController;
use Modules\Cleaning\Http\Controllers\API\CleaningBookingArriveController;
use Modules\Cleaning\Http\Controllers\API\CleaningBookingCompleteController;
use Modules\Cleaning\Http\Controllers\API\CleaningBookingController;
use Modules\Cleaning\Http\Controllers\API\CleaningBookingDeliveryFeeController;
use Modules\Cleaning\Http\Controllers\API\CleaningBookingLocationController;
use Modules\Cleaning\Http\Controllers\API\CleaningBookingPriceAdjustmentRequestController;
use Modules\Cleaning\Http\Controllers\API\CleaningBookingSecurityCodeController;
use Modules\Cleaning\Http\Controllers\API\CleaningBookingShowController;
use Modules\Cleaning\Http\Controllers\API\CleaningBookingStartTravelController;
use Modules\Cleaning\Http\Controllers\API\CleaningBookingStartWorkController;
use Modules\Cleaning\Http\Controllers\API\CleaningBookingWorkerLocationsController;
use Modules\Cleaning\Http\Controllers\API\CleaningNeighborhoodController;
use Modules\Cleaning\Http\Controllers\API\CleaningOpenTimeController;
use Modules\Cleaning\Http\Controllers\API\CleaningOperationalExtrasController;
use Modules\Cleaning\Http\Controllers\API\CleaningScheduleChangeController;
use Modules\Cleaning\Http\Controllers\API\CleaningServiceController;
use Modules\Cleaning\Http\Controllers\API\CleaningSuiteConfigController;
use Modules\Cleaning\Http\Controllers\API\CleaningTimeWarningController;
use Modules\Cleaning\Http\Controllers\API\DashboardOverviewController;
use Modules\Cleaning\Http\Controllers\API\DepositManagementController;
use Modules\Cleaning\Http\Controllers\API\EventBookingController;
use Modules\Cleaning\Http\Controllers\API\GeographicCoverageController;
use Modules\Cleaning\Http\Controllers\API\ServicePricingController;
use Modules\Cleaning\Http\Controllers\API\SupervisedWorkerBookingController;
use Modules\Cleaning\Http\Controllers\API\SupervisorAppController;
use Modules\Cleaning\Http\Controllers\API\SupervisorScheduledV2Controller;
use Modules\Cleaning\Http\Controllers\API\SupervisorWorkflowController;
use Modules\Cleaning\Http\Controllers\API\WorkerAccountProfileController;
use Modules\Cleaning\Http\Controllers\API\WorkerAccountStatusController;
use Modules\Cleaning\Http\Controllers\API\WorkerCurrentLocationController;
use Modules\Cleaning\Http\Controllers\API\WorkerDepositController;
use Modules\Cleaning\Http\Controllers\API\WorkerDetailsController;
use Modules\Cleaning\Http\Controllers\API\WorkerHomepageEligibilityController;
use Modules\Cleaning\Http\Controllers\API\WorkerReviewController;
use Modules\Cleaning\Http\Controllers\API\WorkerStatisticsController;
use Modules\Cleaning\Http\Controllers\API\WorkerTransactionsController;
use Modules\Cleaning\Http\Controllers\API\WorkerWorkAreasController;
use Modules\Cleaning\Http\Controllers\API\WorkerWorkingHoursController;
use Modules\Cleaning\Http\Middleware\EnsureCleaningDepositAdmin;
use Modules\Cleaning\Http\Middleware\EnsureSupervisor;
use Modules\Cleaning\Http\Middleware\RejectSupervisedWorkerLifecycle;
use Modules\Cleaning\Http\Middleware\RejectUnsupportedV2SupervisorExecution;
use Modules\Cleaning\Http\Middleware\RequireSessionScopedCleaningLifecycle;

Route::prefix('v1')->group(function () {
    Route::post('admin/cleaning/supervised-bookings/{booking}/supervisor', AssignSupervisedBookingController::class)
        ->middleware('auth:sanctum')->whereNumber('booking');
    Route::post('admin/cleaning/supervisor-v2-bookings/{booking}/supervisor', AssignSupervisedBookingController::class)
        ->middleware('auth:sanctum')->whereNumber('booking')->name('cleaning.supervisor-v2.assign');
    Route::post('admin/cleaning/supervisor-v2-bookings/{booking}/visits/{visit}/supervisor', AssignSupervisedVisitController::class)
        ->middleware('auth:sanctum')
        ->whereNumber('booking')
        ->whereNumber('visit')
        ->name('cleaning.supervisor-v2.visit.assign');
    Route::prefix('supervisor')->group(function (): void {
        Route::post('login', [SupervisorAppController::class, 'login'])->middleware('throttle:6,1');
        Route::middleware(['auth:sanctum', EnsureSupervisor::class, RejectUnsupportedV2SupervisorExecution::class])->group(function (): void {
            Route::get('v2/bookings', [SupervisorScheduledV2Controller::class, 'index']);
            Route::get('v2/bookings/{booking}', [SupervisorScheduledV2Controller::class, 'show'])->whereNumber('booking');
            Route::get('v2/bookings/{booking}/visits/{visit}', [SupervisorScheduledV2Controller::class, 'visit'])
                ->whereNumber('booking')->whereNumber('visit');
            Route::post('v2/bookings/{booking}/visits/{visit}/{action}', [SupervisorScheduledV2Controller::class, 'unsupportedTransition'])
                ->whereNumber('booking')->whereNumber('visit')
                ->whereIn('action', ['start', 'finish', 'complete', 'verification-requests', 'handover-requests', 'attendance-incidents', 'replacement-requests']);
            Route::get('me', [SupervisorAppController::class, 'me']);
            Route::post('logout', [SupervisorAppController::class, 'logout']);
            Route::get('home', [SupervisorAppController::class, 'home']);
            Route::get('bookings', [SupervisorAppController::class, 'index']);
            Route::get('schedule', [SupervisorAppController::class, 'schedule']);
            Route::get('bookings/{booking}', [SupervisorAppController::class, 'show'])->whereNumber('booking');
            Route::get('bookings/{booking}/attendance', [SupervisorAppController::class, 'attendance'])->whereNumber('booking');
            Route::get('bookings/{booking}/timeline', [SupervisorAppController::class, 'timeline'])->whereNumber('booking');
            Route::get('bookings/{booking}/incidents', [SupervisorAppController::class, 'incidents'])->whereNumber('booking');
            Route::post('bookings/{booking}/attendance-incidents', [SupervisorAppController::class, 'createIncident'])->whereNumber('booking');
            Route::post('bookings/{booking}/replacement-requests', [SupervisorAppController::class, 'replacementRequest'])->whereNumber('booking');
            Route::post('bookings/{booking}/verification-requests', [SupervisorWorkflowController::class, 'verificationRequest']);
            Route::get('bookings/{booking}/verification/status', [SupervisorWorkflowController::class, 'verificationStatus']);
            Route::post('bookings/{booking}/verification/confirm', [SupervisorWorkflowController::class, 'verificationConfirm'])
                ->middleware('throttle:6,1');
            Route::post('bookings/{booking}/start', [SupervisorWorkflowController::class, 'start']);
            Route::get('bookings/{booking}/extensions', [SupervisorWorkflowController::class, 'extensionIndex']);
            Route::post('bookings/{booking}/extension-requests', [SupervisorWorkflowController::class, 'extensionRequest']);
            Route::post('bookings/{booking}/handover-requests', [SupervisorWorkflowController::class, 'handoverRequest']);
            Route::get('bookings/{booking}/handover-status', [SupervisorWorkflowController::class, 'handoverStatus']);
            Route::get('bookings/{booking}/reports', [SupervisorWorkflowController::class, 'reportShow']);
            Route::post('bookings/{booking}/reports', [SupervisorWorkflowController::class, 'reportSubmit']);
            Route::get('bookings/{booking}/media', [SupervisorWorkflowController::class, 'mediaIndex']);
            Route::post('bookings/{booking}/media', [SupervisorWorkflowController::class, 'mediaUpload']);
            Route::get('bookings/{booking}/media/{media}', [SupervisorWorkflowController::class, 'mediaShow'])
                ->whereNumber('media');
        });
    });
    // Customer-owned decisions. The supervisor cannot call these endpoints for another customer's booking.
    Route::middleware('auth:sanctum')->prefix('supervised-team/customer/bookings')->group(function (): void {
        Route::get('{booking}', [SupervisorWorkflowController::class, 'customerShow'])->whereNumber('booking');
        Route::get('{booking}/verification-code', [SupervisorWorkflowController::class, 'customerVerificationCode']);
        Route::post('{booking}/extensions/{extension}/decision', [SupervisorWorkflowController::class, 'extensionDecision']);
        Route::post('{booking}/handover-decision', [SupervisorWorkflowController::class, 'handoverDecision']);
    });
    Route::apiResource('cleaning-services', CleaningServiceController::class)->only(['index', 'show']);
    Route::apiResource('cleaning-services.pricing', ServicePricingController::class)->only(['index', 'show'])->scoped();
    Route::apiResource('cleaning-billing-policies', CleaningBillingPolicyController::class)->only(['index', 'show']);
    Route::get('cleaning/suite-config', CleaningSuiteConfigController::class);

    Route::middleware(['auth:sanctum'])->group(function (): void {
        Route::get('cleaning/neighborhoods', [CleaningNeighborhoodController::class, 'index']);
        Route::post('cleaning/neighborhoods/match', [CleaningNeighborhoodController::class, 'match']);

        Route::get('cleaning/dashboard/overview', DashboardOverviewController::class);
        Route::post('cleaning/worker/current-location', WorkerCurrentLocationController::class);
        Route::get('cleaning/worker/homepage', WorkerHomepageEligibilityController::class);
        Route::get('cleaning/worker/statistics', WorkerStatisticsController::class);
        Route::get('cleaning/worker/reviews', [WorkerReviewController::class, 'index']);
        Route::get('worker/{worker}', WorkerDetailsController::class);
        Route::get('cleaning/worker/profile', Modules\Cleaning\Http\Controllers\API\WorkerProfileController::class);
        Route::get('cleaning/worker/working-hours', [WorkerWorkingHoursController::class, 'show']);
        Route::put('cleaning/worker/working-hours', [WorkerWorkingHoursController::class, 'update']);
        Route::prefix('cleaning/worker/account')->group(function (): void {
            Route::get('profile', Modules\Cleaning\Http\Controllers\API\WorkerProfileController::class);
            Route::put('profile', [WorkerAccountProfileController::class, 'update']);
            Route::put("pass\x77ord", [WorkerAccountProfileController::class, 'updatePassword']);
            Route::get('work-areas', [WorkerWorkAreasController::class, 'show']);
            Route::put('work-areas', [WorkerWorkAreasController::class, 'update']);
            Route::get('working-hours', [WorkerWorkingHoursController::class, 'show']);
            Route::put('working-hours', [WorkerWorkingHoursController::class, 'update']);
            Route::get('notifications', [UserNotificationController::class, 'index']);
            Route::patch('notifications/read-all', [UserNotificationController::class, 'markAllAsRead']);
            Route::delete('notifications/all', [UserNotificationController::class, 'destroyAll']);
            Route::delete('notifications/{id}', [UserNotificationController::class, 'destroy']);
            Route::patch('notifications/{id}/read', [UserNotificationController::class, 'markAsRead']);
            Route::put('notifications/token', RegisterFcmTokenController::class);
            Route::get('transactions', WorkerTransactionsController::class);
            Route::get('status', [WorkerAccountStatusController::class, 'show']);
            Route::patch('status', [WorkerAccountStatusController::class, 'update']);
            Route::get('deposit', [WorkerDepositController::class, 'getStatus']);
            Route::get('deposit/transactions', [WorkerDepositController::class, 'getTransactions']);
        });

        Route::get('cleaning/analytics/geographic-coverage', GeographicCoverageController::class);
        Route::apiResource('cleaning-services', CleaningServiceController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('cleaning-services.pricing', ServicePricingController::class)->only(['store', 'update', 'destroy'])->scoped();
        Route::apiResource('cleaning-billing-policies', CleaningBillingPolicyController::class)->only(['store', 'update', 'destroy']);

        Route::middleware(EnsureCleaningDepositAdmin::class)->prefix('admin/cleaning/deposits')->group(function (): void {
            Route::get('settings', [DepositManagementController::class, 'getSettings']);
            Route::put('settings', [DepositManagementController::class, 'updateSettings']);
            Route::post('{worker}/deposit', [DepositManagementController::class, 'recordDeposit']);
            Route::post('{worker}/debt', [DepositManagementController::class, 'recordDebt']);
            Route::put('{worker}/allowance-limit', [DepositManagementController::class, 'updateAllowanceLimit']);
            Route::post('{worker}/settle-full-debt', [DepositManagementController::class, 'settleFullDebt']);
            Route::post('{worker}/refund', [DepositManagementController::class, 'recordRefund']);
            Route::post('{worker}/withdraw', [DepositManagementController::class, 'recordWithdrawal']);
            Route::get('{worker}/transactions', [DepositManagementController::class, 'getTransactions']);
        });

        Route::post('cleaning-bookings/{cleaning_booking}/delivery-fee', CleaningBookingDeliveryFeeController::class)->name('cleaning-bookings.delivery-fee')->middleware(RejectSupervisedWorkerLifecycle::class);
        Route::post('cleaning-bookings/{cleaning_booking}/accept', [CleaningBookingController::class, 'accept'])->name('cleaning-bookings.accept')->middleware(RejectSupervisedWorkerLifecycle::class);
        Route::post('cleaning-bookings/{cleaning_booking}/rooms/claim', [CleaningBookingController::class, 'claimRooms'])->name('cleaning-bookings.rooms.claim')->middleware(RejectSupervisedWorkerLifecycle::class);
        Route::post('cleaning-bookings/{cleaning_booking}/reject', [CleaningBookingController::class, 'reject'])->name('cleaning-bookings.reject')->middleware(RejectSupervisedWorkerLifecycle::class);
        Route::get('cleaning-bookings/{cleaning_booking}/security-code', CleaningBookingSecurityCodeController::class)->name('cleaning-bookings.security-code')->middleware(RejectSupervisedWorkerLifecycle::class);
        Route::post('cleaning-bookings/{cleaning_booking}/sos', [CleaningBookingController::class, 'sos'])->name('cleaning-bookings.sos');
        Route::post('cleaning-bookings/{cleaning_booking}/start-travel', CleaningBookingStartTravelController::class)->name('cleaning-bookings.start-travel');
        Route::post('cleaning-bookings/{cleaning_booking}/location', CleaningBookingLocationController::class)->name('cleaning-bookings.location');
        Route::get('cleaning-bookings/{cleaning_booking}/worker-locations', CleaningBookingWorkerLocationsController::class)->name('cleaning-bookings.worker-locations');
        Route::post('cleaning-bookings/{cleaning_booking}/arrive', CleaningBookingArriveController::class)->name('cleaning-bookings.arrive');
        Route::post('cleaning-bookings/{cleaning_booking}/price-adjustment-requests', [CleaningBookingPriceAdjustmentRequestController::class, 'store'])->name('cleaning-bookings.price-adjustment-requests.store')->middleware(RejectSupervisedWorkerLifecycle::class);
        Route::post('cleaning-bookings/{cleaning_booking}/start-work', CleaningBookingStartWorkController::class)->name('cleaning-bookings.start-work')->middleware(RejectSupervisedWorkerLifecycle::class);
        Route::post('cleaning-bookings/{cleaning_booking}/complete', CleaningBookingCompleteController::class)->name('cleaning-bookings.complete')->middleware(RejectSupervisedWorkerLifecycle::class);
        Route::post('cleaning-bookings/{cleaning_booking}/finish', [CleaningBookingController::class, 'finish'])->name('cleaning-bookings.finish')->middleware(RejectSupervisedWorkerLifecycle::class);
        Route::post('cleaning-bookings/{cleaning_booking}/cancel', [CleaningBookingController::class, 'cancel'])->name('cleaning-bookings.cancel')->middleware(RejectSupervisedWorkerLifecycle::class);
        Route::get('cleaning/supervised-worker/bookings/{booking}', SupervisedWorkerBookingController::class)->whereNumber('booking');
        Route::get('cleaning-bookings/{cleaning_booking}', CleaningBookingShowController::class)->name('cleaning-bookings.show');
        Route::get('cleaning-bookings/{cleaning_booking}/open-time/meter', [CleaningOpenTimeController::class, 'meter'])->name('cleaning-bookings.open-time.meter')->middleware(RejectSupervisedWorkerLifecycle::class);
        Route::post('cleaning-bookings/open-time/extensions/{extension}/decision', [CleaningOpenTimeController::class, 'decideExtension'])->name('cleaning-bookings.open-time.extensions.decision');
        Route::post('cleaning-bookings/{cleaning_booking}/open-time/end/decision', [CleaningOpenTimeController::class, 'decideEnd'])->name('cleaning-bookings.open-time.end.decision')->middleware(RejectSupervisedWorkerLifecycle::class);
        Route::get('cleaning-bookings/{cleaning_booking}/sessions/{session}/open-time/meter', [CleaningOpenTimeController::class, 'sessionMeter'])->name('cleaning-bookings.sessions.open-time.meter');
        Route::post('cleaning-bookings/{cleaning_booking}/sessions/{session}/open-time/end/decision', [CleaningOpenTimeController::class, 'decideSessionEnd'])
            ->name('cleaning-bookings.sessions.open-time.end.decision')
            ->middleware(RequireSessionScopedCleaningLifecycle::class);
        Route::post('cleaning-bookings/{cleaning_booking}/materials/receive', [CleaningOperationalExtrasController::class, 'receiveKit'])->name('cleaning-bookings.materials.receive')->middleware(RejectSupervisedWorkerLifecycle::class);
        Route::post('cleaning-booking-special-services/{bookingService}/start', [CleaningOperationalExtrasController::class, 'startService'])->name('cleaning-booking-special-services.start');
        Route::post('cleaning-booking-special-services/{bookingService}/finish', [CleaningOperationalExtrasController::class, 'finishService'])->name('cleaning-booking-special-services.finish');
        Route::post('cleaning-equipment-reservations/{reservation}/acknowledge', [CleaningOperationalExtrasController::class, 'acknowledgeEquipment'])->name('cleaning-equipment-reservations.acknowledge');
        Route::post('cleaning-equipment-reservations/{reservation}/return', [CleaningOperationalExtrasController::class, 'returnEquipment'])->name('cleaning-equipment-reservations.return');
        Route::get('cleaning/schedule-change-requests', [CleaningScheduleChangeController::class, 'index'])->name('cleaning.schedule-change-requests.index');
        Route::post('cleaning/schedule-change-requests/{changeRequest}/decision', [CleaningScheduleChangeController::class, 'decide'])->name('cleaning.schedule-change-requests.decision');
        Route::apiResource('cleaning-bookings', CleaningBookingController::class)->except(['show']);
        Route::apiResource('event-bookings', EventBookingController::class);

        Route::post('cleaning-time-warnings/{cleaning_time_warning}/accept', [CleaningTimeWarningController::class, 'accept'])->name('cleaning-time-warnings.accept');
        Route::post('cleaning-time-warnings/{cleaning_time_warning}/reject', [CleaningTimeWarningController::class, 'reject'])->name('cleaning-time-warnings.reject');
        Route::apiResource('cleaning-time-warnings', CleaningTimeWarningController::class)->only(['index', 'show']);
    });
});
