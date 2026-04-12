<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\User\Services\UserOrderHubService;

final readonly class UserOrderShowController
{
    public function __construct(
        private UserOrderHubService $orders,
    ) {}

    public function __invoke(string $section, int $orderId): JsonResponse
    {
        $this->validateSection($section);

        return response()->json([
            'data' => $this->orders->show((int) auth()->id(), $section, $orderId),
        ]);
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
