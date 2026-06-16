<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Controllers\API\Driver;

use Illuminate\Http\JsonResponse;
use Modules\Delivery\Http\Resources\DeliveryFinancialSummaryResource;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Services\FinancialLedgerService;

final class DriverFinancialController
{
    public function __construct(
        private readonly FinancialLedgerService $ledgerService,
    ) {}

    public function __invoke(\Illuminate\Http\Request $request): JsonResponse
    {
        /** @var DeliveryDriver $driver */
        $driver = $request->attributes->get('deliveryDriver');

        $account = $this->ledgerService
            ->ensureAccount($driver, 'SYP')
            ->load(['transactions' => fn ($q) => $q->latest('id')->limit(10)]);

        return response()->json([
            'data' => DeliveryFinancialSummaryResource::make($account),
        ]);
    }
}
