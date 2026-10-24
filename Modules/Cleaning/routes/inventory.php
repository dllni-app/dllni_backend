<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Cleaning\Http\Controllers\API\CleaningInventoryCustodyController;
use Modules\Cleaning\Http\Middleware\EnsureSupervisor;

Route::middleware('auth:sanctum')->prefix('v1')->group(function (): void {
    Route::get('cleaning/inventory/bookings/{booking}', [CleaningInventoryCustodyController::class, 'showWorker'])
        ->whereNumber('booking');

    Route::middleware(EnsureSupervisor::class)->prefix('supervisor')->group(function (): void {
        Route::get('bookings/{booking}/inventory', [CleaningInventoryCustodyController::class, 'showSupervisor'])
            ->whereNumber('booking');
        Route::post('bookings/{booking}/materials/receive', [CleaningInventoryCustodyController::class, 'receiveSupervisorKit'])
            ->whereNumber('booking');
        Route::post('equipment-reservations/{reservation}/acknowledge', [CleaningInventoryCustodyController::class, 'acknowledgeSupervisorEquipment'])
            ->whereNumber('reservation');
        Route::post('equipment-reservations/{reservation}/return', [CleaningInventoryCustodyController::class, 'returnSupervisorEquipment'])
            ->whereNumber('reservation');
    });
});
