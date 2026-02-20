<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Cleaning\Http\Controllers\API\CleaningBillingPolicyController;
use Modules\Cleaning\Http\Controllers\API\CleaningBookingController;
use Modules\Cleaning\Http\Controllers\API\CleaningServiceController;
use Modules\Cleaning\Http\Controllers\API\CleaningTimeWarningController;
use Modules\Cleaning\Http\Controllers\API\DashboardOverviewController;
use Modules\Cleaning\Http\Controllers\API\EventBookingController;
use Modules\Cleaning\Http\Controllers\API\GeographicCoverageController;
use Modules\Cleaning\Http\Controllers\API\ServicePricingController;
use Modules\Cleaning\Http\Controllers\API\WorkerHomepageController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('cleaning/dashboard/overview', DashboardOverviewController::class);
    Route::get('cleaning/worker/homepage', WorkerHomepageController::class);
    Route::get('cleaning/analytics/geographic-coverage', GeographicCoverageController::class);
    Route::apiResource('cleaning-bookings', CleaningBookingController::class);
    Route::apiResource('event-bookings', EventBookingController::class);
    Route::apiResource('cleaning-time-warnings', CleaningTimeWarningController::class)->only(['index', 'show']);
    Route::apiResource('cleaning-services', CleaningServiceController::class);
    Route::apiResource('cleaning-services.pricing', ServicePricingController::class)->scoped();
    Route::apiResource('cleaning-billing-policies', CleaningBillingPolicyController::class);
});
