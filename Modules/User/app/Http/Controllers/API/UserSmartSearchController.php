<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\User\Http\Requests\UserSmartSearchRequest;
use Modules\User\Services\RestaurantSmartSearchService;
use Modules\User\Services\SmartSearchIntentService;
use Modules\User\Services\SupermarketSmartSearchService;

final class UserSmartSearchController
{
    public function __construct(
        private readonly SmartSearchIntentService $intentService,
        private readonly RestaurantSmartSearchService $restaurantSearch,
        private readonly SupermarketSmartSearchService $supermarketSearch,
    ) {}

    public function __invoke(UserSmartSearchRequest $request): JsonResponse
    {
        $startedAt = hrtime(true);
        $validated = $request->validated();
        $section = (string) $validated['section'];
        $query = (string) $validated['query'];
        $locale = is_string($validated['locale'] ?? null) ? $validated['locale'] : 'ar';
        $topK = isset($validated['topK']) ? (int) $validated['topK'] : 20;
        $searchId = (string) Str::uuid();

        $interpretation = $this->intentService->interpret($section, $query, $locale);
        $results = $section === 'restaurant'
            ? $this->restaurantSearch->search($interpretation, $topK)
            : $this->supermarketSearch->search($interpretation, $topK);

        $durationMs = round((hrtime(true) - $startedAt) / 1_000_000, 2);

        Log::info('User smart search completed.', [
            'search_id' => $searchId,
            'section' => $section,
            'intent_source' => $interpretation['source'] ?? null,
            'goal' => $interpretation['goal'] ?? null,
            'duration_ms' => $durationMs,
            'candidate_count' => $results['candidateCount'] ?? null,
            'unresolved_items' => is_array($results['unresolvedItems'] ?? null)
                ? count($results['unresolvedItems'])
                : null,
        ]);

        return response()->json([
            'data' => [
                'searchId' => $searchId,
                'section' => $section,
                'query' => $query,
                'interpretation' => $interpretation,
                'results' => $results,
                'meta' => [
                    'durationMs' => $durationMs,
                    'fallbackUsed' => ($interpretation['source'] ?? null) === 'fallback',
                ],
            ],
        ]);
    }
}
