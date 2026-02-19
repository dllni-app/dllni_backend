<?php

declare(strict_types=1);

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;

Route::get('/', fn (): View => view('welcome'));

Route::resource('/users', App\Http\Controllers\UserController::class);
