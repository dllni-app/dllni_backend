<?php

use Illuminate\Support\Facades\Route;
use Modules\Resturants\Http\Controllers\ResturantsController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('resturants', ResturantsController::class)->names('resturants');
});
