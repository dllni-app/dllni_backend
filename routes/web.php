<?php

declare(strict_types=1);

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DeepLinks\OpenDeepLinkController;
use App\Http\Controllers\DeepLinks\ShortLinkRedirectController;

Route::get('/', fn(): View => view('welcome'));

Route::get('/reset-password/{token}', function (string $token, Illuminate\Http\Request $request) {
    return redirect()->to(config('app.frontend_url', url('/')) . '/reset-password?token=' . $token . '&email=' . urlencode($request->query('email', '')));
})->name('password.reset');

Route::get('/product/{identifier}', OpenDeepLinkController::class)
    ->where('identifier', '[A-Za-z0-9\-_.]+')
    ->defaults('type', 'product')
    ->name('deep-links.product');

Route::get('/restaurant/{identifier}', OpenDeepLinkController::class)
    ->where('identifier', '[A-Za-z0-9\-_.]+')
    ->defaults('type', 'restaurant')
    ->name('deep-links.restaurant');

Route::get('/vote/{identifier}', OpenDeepLinkController::class)
    ->where('identifier', '[A-Za-z0-9\-_.]+')
    ->defaults('type', 'vote')
    ->name('deep-links.vote');

Route::get('/group-order/{identifier}', OpenDeepLinkController::class)
    ->where('identifier', '[A-Za-z0-9\-_.]+')
    ->defaults('type', 'group-order')
    ->name('deep-links.group-order');

Route::get('/s/{code}', ShortLinkRedirectController::class)
    ->where('code', '[A-Za-z0-9\-]+')
    ->name('deep-links.short');
