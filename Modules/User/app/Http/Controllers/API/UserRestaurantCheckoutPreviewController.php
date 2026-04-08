<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\UserRestaurantCheckoutPreviewRequest;
use Modules\User\Services\UserRestaurantCheckoutPipelineService;

final class UserRestaurantCheckoutPreviewController
{
    public function __construct(
        private readonly UserRestaurantCheckoutPipelineService $checkout,
    ) {}

    public function __invoke(UserRestaurantCheckoutPreviewRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->checkout->preview(
                userId: (int) $request->user()->id,
                merchantId: (int) $request->integer('merchantId'),
                fulfillmentType: (string) $request->string('fulfillmentType'),
                receiveMode: (string) $request->string('receiveMode'),
                scheduledAt: $request->input('scheduledAt'),
                couponCode: $request->input('couponCode'),
                note: $request->input('note'),
            ),
        ]);
    }
}
