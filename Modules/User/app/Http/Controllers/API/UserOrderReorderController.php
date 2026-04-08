<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\User\Services\UserOrderHubService;

final class UserOrderReorderController
{
    public function __construct(
        private readonly UserOrderHubService $orders,
    ) {}

    public function __invoke(string $section, int $orderId): JsonResponse
    {
        $this->validateSection($section);

        return response()->json([
            'data' => $this->orders->reorder((int) auth()->id(), $section, $orderId),
        ], 201);
    }

    private function validateSection(string $section): void
    {
        $validator = validator(['section' => $section], [
            'section' => ['required', Rule::in(['restaurant', 'supermarket'])],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }
    }
}
