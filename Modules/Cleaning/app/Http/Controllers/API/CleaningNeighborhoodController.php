<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\Cleaning\Http\Requests\CleaningNeighborhoodIndexRequest;
use Modules\Cleaning\Http\Requests\CleaningNeighborhoodMatchRequest;
use Modules\Cleaning\Http\Resources\CleaningNeighborhoodResource;
use Modules\Cleaning\Services\CleaningNeighborhoodResolver;
use Modules\Cleaning\Support\CleaningNeighborhoodNameNormalizer;

final class CleaningNeighborhoodController
{
    public function __construct(
        private readonly CleaningNeighborhoodResolver $resolver,
    ) {}

    public function index(CleaningNeighborhoodIndexRequest $request)
    {
        $items = $this->resolver->list(
            search: $request->validated('search'),
            city: $request->validated('city', CleaningNeighborhoodNameNormalizer::ALEPPO_CITY),
            activeOnly: $request->activeOnly(),
        );

        return CleaningNeighborhoodResource::collection($items);
    }

    public function match(CleaningNeighborhoodMatchRequest $request): JsonResponse
    {
        $matched = $this->resolver->matchText(
            $request->validated('text'),
            $request->validated('city', CleaningNeighborhoodNameNormalizer::ALEPPO_CITY),
        );

        if ($matched === null) {
            return response()->json([
                'matched' => false,
                'data' => null,
                'message' => "\u{644}\u{645} \u{646}\u{62a}\u{645}\u{643}\u{646} \u{645}\u{646} \u{62a}\u{62d}\u{62f}\u{64a}\u{62f} \u{627}\u{644}\u{62d}\u{64a} \u{645}\u{646} \u{627}\u{644}\u{62e}\u{631}\u{64a}\u{637}\u{629}\u{60c} \u{627}\u{644}\u{631}\u{62c}\u{627}\u{621} \u{627}\u{62e}\u{62a}\u{64a}\u{627}\u{631}\u{647} \u{645}\u{646} \u{627}\u{644}\u{642}\u{627}\u{626}\u{645}\u{629}.",
            ]);
        }

        return response()->json([
            'matched' => true,
            'data' => CleaningNeighborhoodResource::make($matched)->resolve($request),
        ]);
    }
}
