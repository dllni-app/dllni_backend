<?php

declare(strict_types=1);

use App\Http\Controllers\API\AppDownloadController;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DeepLinks\OpenDeepLinkLandingController;
use App\Http\Controllers\DeepLinks\OpenDeepLinkController;
use App\Http\Controllers\DeepLinks\ShortLinkRedirectController;

Route::prefix('v1/apps')->group(function (): void {
    Route::get('download', AppDownloadController::class);
});

Route::get('/', function (): Response {
    $content = view('welcome')->render();
    $footer = view('partials.landing-copyright')->render();

    return response(str_replace(
        "    </main>\n</div>",
        "    </main>\n\n{$footer}\n</div>",
        $content,
    ));
});

Route::get('/reset-password/{token}', function (string $token, Illuminate\Http\Request $request) {
    return redirect()->to(config('app.frontend_url', url('/')) . '/reset-password?token=' . $token . '&email=' . urlencode($request->query('email', '')));
})->name('password.reset');

Route::view('/legal/user-app', 'user-app')->name('legal.user-app');
Route::view('/legal/merchant-app', 'merchant-app')->name('legal.merchant-app');
Route::view('/legal/delivery-app', 'delivery-app')->name('legal.delivery-app');
Route::view('/legal/cleaning-worker-app', 'cleaning-worker-app')->name('legal.cleaning-worker-app');
Route::get('/qa/firebase/browser-token', function (): View {
    return view('qa.firebase-browser-token', [
        'firebaseDebugConfig' => [
            'webConfig' => [
                'apiKey' => config('fcm.web.api_key'),
                'authDomain' => config('fcm.web.auth_domain'),
                'projectId' => config('fcm.web.project_id'),
                'storageBucket' => config('fcm.web.storage_bucket'),
                'messagingSenderId' => config('fcm.web.messaging_sender_id'),
                'appId' => config('fcm.web.app_id'),
                'measurementId' => config('fcm.web.measurement_id'),
            ],
            'vapidKey' => config('fcm.web.vapid_key'),
            'serviceWorkerPath' => '/firebase-messaging-sw.js',
            'registerEndpoint' => '/api/v1/user/notifications/token',
        ],
    ]);
})->name('qa.firebase.browser-token');

/**
 * Build Android Digital Asset Links payload.
 */
$assetLinksPayload = static function (): array {
    $packageName = (string) config('deep_links.android_app_package_name', '');
    $fingerprints = array_values((array) config('deep_links.android_sha256_cert_fingerprints', []));

    if ($packageName === '' || $fingerprints === []) {
        return [];
    }

    return [
        [
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => $packageName,
                'sha256_cert_fingerprints' => $fingerprints,
            ],
        ],
    ];
};

/**
 * Build Apple App Site Association payload.
 */
$appleAssociationPayload = static function (): array {
    $appIds = array_values((array) config('deep_links.ios_app_ids', []));
    $paths = array_values((array) config('deep_links.ios_paths', []));

    $details = [];
    if ($appIds !== []) {
        $details[] = [
            'appID' => (string) $appIds[0],
            'appIDs' => $appIds,
            'paths' => $paths,
        ];
    }

    return [
        'applinks' => [
            'apps' => [],
            'details' => $details,
        ],
    ];
};

Route::get('/.well-known/assetlinks.json', fn (): JsonResponse => response()->json($assetLinksPayload()));
Route::get('/assetlinks.json', fn (): JsonResponse => response()->json($assetLinksPayload()));

Route::get('/.well-known/apple-app-site-association', fn (): JsonResponse => response()->json($appleAssociationPayload()));
Route::get('/apple-app-site-association', fn (): JsonResponse => response()->json($appleAssociationPayload()));

Route::get('/open', OpenDeepLinkLandingController::class)->name('deep-links.open');

Route::get('/product/{identifier}', OpenDeepLinkController::class)
    ->where('identifier', '[A-Za-z0-9\-_.~%]+')
    ->defaults('type', 'product')
    ->name('deep-links.product');

Route::get('/restaurant/{identifier}', OpenDeepLinkController::class)
    ->where('identifier', '[A-Za-z0-9\-_.~%]+')
    ->defaults('type', 'restaurant')
    ->name('deep-links.restaurant');

Route::get('/store/{identifier}', OpenDeepLinkController::class)
    ->where('identifier', '[A-Za-z0-9\-_.~%]+')
    ->defaults('type', 'store')
    ->name('deep-links.store');

Route::get('/vote/{identifier}', OpenDeepLinkController::class)
    ->where('identifier', '[A-Za-z0-9\-_.~%]+')
    ->defaults('type', 'vote')
    ->name('deep-links.vote');

Route::get('/group-order/{identifier}', OpenDeepLinkController::class)
    ->where('identifier', '[A-Za-z0-9\-_.~%]+')
    ->defaults('type', 'group-order')
    ->name('deep-links.group-order');

Route::get('/s/{code}', ShortLinkRedirectController::class)
    ->where('code', '[A-Za-z0-9\-_]+')
    ->name('deep-links.short');
