<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\RegisterRequest;
use Modules\User\Services\RegisterUserService;

final class RegisterController
{
    public function __construct(
        private readonly RegisterUserService $registerUserService,
    ) {}

    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $expiresAt = $this->registerUserService->register($request->validated());

        return response()->json([
            'message' => "\u{062A}\u{0645} \u{0625}\u{0631}\u{0633}\u{0627}\u{0644} \u{0631}\u{0645}\u{0632} \u{0627}\u{0644}\u{062A}\u{062D}\u{0642}\u{0642}.",
            'expiresAt' => $expiresAt->toIso8601String(),
        ]);
    }
}
