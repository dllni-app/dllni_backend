<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\User\Services\UserPopularSearchService;

final class UserPopularSearchesController
{
    public function __construct(
        private readonly UserPopularSearchService $popularSearches,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'section' => [
                'required',
                'string',
                Rule::in($this->popularSearches->sections()),
            ],
            'filter' => [
                'sometimes',
                'string',
                Rule::in($this->popularSearches->filters()),
            ],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);

        $section = (string) $validated['section'];
        $filter = isset($validated['filter']) ? (string) $validated['filter'] : null;
        $limit = isset($validated['limit']) ? (int) $validated['limit'] : 6;

        return response()->json([
            'section' => $section,
            'filter' => $filter,
            'data' => $this->popularSearches->popular($section, $filter, $limit),
        ]);
    }
}
