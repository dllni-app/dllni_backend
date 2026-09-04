<?php

declare(strict_types=1);

use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\AppDownloadController;
use App\Http\Controllers\API\CancellationPolicyController;
use App\Http\Controllers\API\DisputeController;
use App\Http\Controllers\API\ServiceAddonController;
use App\Http\Controllers\API\SupportCaseController;
use App\Http\Controllers\API\SystemAlertController;
use App\Http\Controllers\API\TravelCostConfigController;
use App\Http\Controllers\API\UserAuthController;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\API\UserNotificationController;
use App\Http\Controllers\API\WorkerController;
use App\Http\Controllers\DeepLinks\OpenDeepLinkController;
use App\Http\Controllers\DeepLinks\ResolveDeepLinkController;
use App\Http\Controllers\DeepLinks\TrackDeepLinkEventController;
use App\Http\Controllers\Test\SmsProviderTestController;
use App\Http\Controllers\Test\SmsQueueTestController;
use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\API\UserPopularSearchesController;

Route::post('login', [UserAuthController::class, 'login']);
Route::middleware(['auth:sanctum'])->post('logout', [UserAuthController::class, 'logout']);

Route::prefix('dashboard')->group(function (): void {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::middleware(['auth:sanctum', 'dashboard.admin'])->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

Route::apiResource('users', UserController::class);

Route::prefix('v1/deep-links')->group(function (): void {
    Route::post('resolve', ResolveDeepLinkController::class);
    Route::post('events', TrackDeepLinkEventController::class);
    Route::get('{type}/{identifier}', OpenDeepLinkController::class)
        ->whereIn('type', ['product', 'restaurant', 'store', 'vote', 'group-order'])
        ->where('identifier', '[A-Za-z0-9\-_.~%]+')
        ->name('api.deep-links.open');
});

Route::prefix('v1/apps')->group(function (): void {
    Route::get('download', AppDownloadController::class);
});

Route::get('v1/user/popular-searches', UserPopularSearchesController::class);

Route::prefix('v1/test')
    ->middleware(['throttle:5,1'])
    ->group(function (): void {
        Route::post('sms-provider', SmsProviderTestController::class);
        Route::post('sms-queue', [SmsQueueTestController::class, 'store']);
        Route::get('sms-queue/{smsMessage}', [SmsQueueTestController::class, 'show'])
            ->middleware('signed')
            ->name('api.test.sms-queue.status');
    });

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function (): void {
    Route::get('notifications', [UserNotificationController::class, 'index'])->name('notifications.index');
    Route::patch('notifications/{id}/read', [UserNotificationController::class, 'markAsRead'])->name('notifications.markAsRead');
    Route::apiResource('workers', WorkerController::class);

    Route::get('support-cases', [SupportCaseController::class, 'index'])->name('support-cases.index');
    Route::post('support-cases', [SupportCaseController::class, 'store'])->name('support-cases.store');
    Route::get('support-cases/{supportCase}', [SupportCaseController::class, 'show'])->name('support-cases.show');
    Route::post('support-cases/{supportCase}/messages', [SupportCaseController::class, 'storeMessage'])->name('support-cases.messages.store');

    // Legacy endpoints remain available while installed app versions migrate.
    Route::post('disputes/{dispute}/messages', [DisputeController::class, 'storeMessage']);
    Route::apiResource('disputes', DisputeController::class);
    Route::apiResource('system-alerts', SystemAlertController::class)->only(['index', 'show', 'update']);
    Route::apiResource('service-addons', ServiceAddonController::class);
    Route::apiResource('travel-cost-configs', TravelCostConfigController::class);
    Route::get('cancellation-policy', [CancellationPolicyController::class, 'show']);
});
