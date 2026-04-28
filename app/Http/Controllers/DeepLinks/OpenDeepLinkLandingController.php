<?php

declare(strict_types=1);

namespace App\Http\Controllers\DeepLinks;

use App\Services\DeepLinks\DeepLinkAnalyticsService;
use App\Services\DeepLinks\DeepLinkResolverService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class OpenDeepLinkLandingController
{
    public function __construct(
        private readonly DeepLinkResolverService $resolver,
        private readonly DeepLinkAnalyticsService $analytics,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $deepLink = (string) $request->query('deep_link', '');
        $currentUserId = $request->user() !== null ? (int) $request->user()->getAuthIdentifier() : null;

        $resolved = $this->resolver->resolve(
            urlOrPath: $deepLink,
            currentUserId: $currentUserId,
        );

        $this->analytics->log('open', $request, $resolved);

        if (($resolved['status'] ?? null) !== 'ok') {
            $invalid = (string) config('deep_links.invalid_fallback_url');

            return redirect()->away($invalid . '?reason=' . urlencode((string) ($resolved['status'] ?? 'not_found')));
        }

        $landing = (string) config('deep_links.web_landing_url');
        $canonical = (string) ($resolved['canonical_url'] ?? $deepLink);

        $query = array_filter([
            'deep_link' => $canonical,
            'source' => $request->query('source'),
            'medium' => $request->query('medium'),
            'campaign' => $request->query('campaign'),
            'sharer_id' => $request->query('sharer_id'),
            'store_url' => (string) config('deep_links.store_landing_url'),
        ], static fn($value): bool => $value !== null && $value !== '');

        return redirect()->away($landing . '?' . http_build_query($query));
    }
}
