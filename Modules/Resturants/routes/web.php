<?php

use Illuminate\Support\Facades\Route;
use Modules\Resturants\Http\Controllers\ResturantsController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('resturants', ResturantsController::class)->names('resturants');
});
