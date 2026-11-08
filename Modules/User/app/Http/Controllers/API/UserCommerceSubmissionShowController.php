<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\User\Services\CommerceCheckoutReceiptService;

final class UserCommerceSubmissionShowController
{
    public function __invoke(Request $request, string $idempotencyKey, CommerceCheckoutReceiptService $receipts): JsonResponse
    {
        $validated = validator(
            ['idempotencyKey' => $idempotencyKey, 'section' => $request->query('section')],
            ['idempotencyKey' => ['required', 'string', 'min:16', 'max:80', 'regex:/^[a-zA-Z0-9_-]+$/D'], 'section' => ['sometimes', 'nullable', Rule::in(['restaurant', 'supermarket'])]],
        )->validate();

        return response()->json([
            'data' => $receipts->lookup((int) $request->user()->id, $idempotencyKey, $validated['section'] ?? null),
        ]);
    }
}
