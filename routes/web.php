<?php

declare(strict_types=1);

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;

Route::get('/', fn (): View => view('welcome'));

Route::get('/reset-password/{token}', function (string $token, Illuminate\Http\Request $request) {
    return redirect()->to(config('app.frontend_url', url('/')).'/reset-password?token='.$token.'&email='.urlencode($request->query('email', '')));
})->name('password.reset');
