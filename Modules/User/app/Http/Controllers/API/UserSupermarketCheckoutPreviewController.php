<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\UserSupermarketCheckoutPreviewRequest;
use Modules\User\Services\UserSupermarketCheckoutPipelineService;

final class UserSupermarketCheckoutPreviewController
{
    public function __construct(
        private readonly UserSupermarketCheckoutPipelineService $checkout,
    ) {}

    public function __invoke(UserSupermarketCheckoutPreviewRequest $request, int $cartId): JsonResponse
    {
        return response()->json([
            'data' => $this->checkout->preview(
                userId: (int) $request->user()->id,
                cartId: $cartId,
                fulfillmentType: (string) $request->string('fulfillmentType'),
                receiveMode: (string) $request->string('receiveMode'),
                scheduledAt: $request->input('scheduledAt'),
                couponCode: $request->input('couponCode'),
                note: $request->input('note'),
                addressId: $request->integer('addressId') ?: null,
            ),
        ]);
    }
}
