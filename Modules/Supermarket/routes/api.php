<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Supermarket\Http\Controllers\API\SmStoreController;

Route::prefix('v1')->group(function () {
    Route::apiResource('sm-stores', SmStoreController::class)->names('sm-stores');
});
