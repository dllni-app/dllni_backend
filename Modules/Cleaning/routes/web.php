<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\CleaningBookingTrackingController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')
    ->get('/admin/cleaning-bookings/{cleaning_booking}/tracking', CleaningBookingTrackingController::class)
    ->name('admin.cleaning-bookings.tracking');

Route::middleware(['auth', 'verified'])->group(function (): void {
    //
});
