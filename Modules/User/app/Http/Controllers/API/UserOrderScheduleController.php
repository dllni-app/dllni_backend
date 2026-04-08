<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\User\Http\Requests\UserOrderScheduleRequest;
use Modules\User\Services\UserOrderHubService;

final class UserOrderScheduleController
{
    public function __construct(
        private readonly UserOrderHubService $orders,
    ) {}

    public function __invoke(UserOrderScheduleRequest $request, string $section, int $orderId): JsonResponse
    {
        $this->validateSection($section);

        return response()->json([
            'data' => $this->orders->schedule(
                userId: (int) $request->user()->id,
                section: $section,
                orderId: $orderId,
                scheduledAt: (string) $request->string('scheduledAt'),
            ),
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
