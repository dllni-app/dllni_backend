<?php

declare(strict_types=1);

namespace App\Http\Controllers\DeepLinks;

use App\Models\DeepLinkShortUrl;
use App\Services\DeepLinks\DeepLinkAnalyticsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ShortLinkRedirectController
{
    private DeepLinkAnalyticsService $analytics;

    public function __construct(
        DeepLinkAnalyticsService $analytics,
    ) {
        $this->analytics = $analytics;
    }

    public function __invoke(Request $request, string $code): RedirectResponse
    {
        $short = DeepLinkShortUrl::query()->where('code', $code)->first();

        if ($short === null || ! $short->is_active) {
            return redirect()->away((string) config('deep_links.invalid_fallback_url'));
        }

        if ($short->expires_at !== null && now()->greaterThan($short->expires_at)) {
            return redirect()->away((string) config('deep_links.invalid_fallback_url'));
        }

        if ($short->max_clicks !== null && $short->clicks >= $short->max_clicks) {
            return redirect()->away((string) config('deep_links.invalid_fallback_url'));
        }

        $short->increment('clicks');

        $this->analytics->log('short_redirect', $request, [
            'type' => 'short-link',
            'id' => (int) $short->id,
            'slug' => $short->code,
            'status' => 'ok',
        ], [
            'full_url' => $short->target_url,
            'meta' => ['code' => $short->code, 'target_url' => $short->target_url],
        ]);

        return redirect()->away($short->target_url);
    }
}
