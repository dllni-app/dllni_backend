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
    $googlePlayUrl = 'https://play.google.com/store/apps/details?id=com.alnadha.app&pcampaignid=web_share';
    $directDownloadUrl = 'https://alnadha.net/v1/apps/download?appType=user_app';
    $whatsappNumber = '963948388930';

    $content = view('welcome')->render();
    $footer = view('partials.landing-copyright')->render();

    $directDownloadButton = <<<HTML
                        <a class="store-button" href="{$directDownloadUrl}" aria-label="تحميل التطبيق مباشرة">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14"/>
                            </svg>
                            <span class="store-button__text"><small>تحميل</small>مباشر</span>
                        </a>
HTML;

    $content = preg_replace(
        '/<a class="store-button" href="#" aria-label="تحميل التطبيق من App Store">.*?<\/a>/s',
        $directDownloadButton,
        $content,
        1,
    ) ?? $content;

    $content = str_replace(
        [
            'href="#" aria-label="تحميل التطبيق من Google Play"',
            '<a class="store-button" href="#">App Store</a>',
            '<a class="store-button store-button--secondary" href="#">Google Play</a>',
            'تواصل مع الإدارة',
        ],
        [
            'href="'.$googlePlayUrl.'" target="_blank" rel="noopener noreferrer" aria-label="تحميل التطبيق من Google Play"',
            '<a class="store-button" href="'.$directDownloadUrl.'">تحميل مباشر</a>',
            '<a class="store-button store-button--secondary" href="'.$googlePlayUrl.'" target="_blank" rel="noopener noreferrer">Google Play</a>',
            'تواصل مع الإدارة عبر واتساب',
        ],
        $content,
    );

    $content = preg_replace_callback(
        '/href="mailto:[^"]+\?subject=([^"]+)"/',
        static function (array $matches) use ($whatsappNumber): string {
            return sprintf(
                'href="https://wa.me/%s?text=%s" target="_blank" rel="noopener noreferrer"',
                $whatsappNumber,
                $matches[1],
            );
        },
        $content,
    ) ?? $content;

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
Route::view('/legal/user-app/terms', 'user-app-terms')->name('legal.user-app.terms');
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