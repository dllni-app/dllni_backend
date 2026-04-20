<?php

declare(strict_types=1);

namespace App\Http\Controllers\DeepLinks;

use App\Http\Requests\ResolveDeepLinkRequest;
use App\Services\DeepLinks\DeepLinkAnalyticsService;
use App\Services\DeepLinks\DeepLinkResolverService;
use Illuminate\Http\JsonResponse;

final class ResolveDeepLinkController
{
    private DeepLinkResolverService $resolver;

    private DeepLinkAnalyticsService $analytics;

    public function __construct(
        DeepLinkResolverService $resolver,
        DeepLinkAnalyticsService $analytics,
    ) {
        $this->resolver = $resolver;
        $this->analytics = $analytics;
    }

    public function __invoke(ResolveDeepLinkRequest $request): JsonResponse
    {
        $currentUserId = $request->user() !== null ? (int) $request->user()->getAuthIdentifier() : null;

        $resolved = $this->resolver->resolve(
            urlOrPath: (string) $request->string('url'),
            currentUserId: $currentUserId,
        );

        $this->analytics->log('resolve', $request, $resolved);

        return response()->json($resolved);
    }
}
