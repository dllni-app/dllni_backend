<?php

declare(strict_types=1);

namespace App\Http\Controllers\DeepLinks;

use App\Http\Requests\TrackDeepLinkEventRequest;
use App\Services\DeepLinks\DeepLinkAnalyticsService;
use App\Services\DeepLinks\DeepLinkResolverService;
use Illuminate\Http\JsonResponse;

final class TrackDeepLinkEventController
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

    public function __invoke(TrackDeepLinkEventRequest $request): JsonResponse
    {
        $currentUserId = $request->user() !== null ? (int) $request->user()->getAuthIdentifier() : null;

        $resolution = [];
        if ($request->filled('url')) {
            $resolution = $this->resolver->resolve(
                urlOrPath: (string) $request->string('url'),
                currentUserId: $currentUserId,
            );
        }

        $this->analytics->log(
            action: (string) $request->string('action'),
            request: $request,
            resolution: $resolution,
            overrides: [
                'source' => $request->input('source'),
                'medium' => $request->input('medium'),
                'campaign' => $request->input('campaign'),
                'sharer_id' => $request->input('sharer_id'),
                'platform' => $request->input('platform'),
            ],
        );

        return response()->json(['status' => 'ok']);
    }
}
