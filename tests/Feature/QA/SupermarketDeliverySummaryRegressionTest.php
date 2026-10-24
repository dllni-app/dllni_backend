<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\DeliveryOrderFactory;
use Database\Factories\SmOrderFactory;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Services\DeliveryOrderCreationService;
use Modules\Delivery\Support\DeliveryPresentation;
use Modules\User\Services\UserOrderHubService;

it('shows the actual stopped courier lifecycle separately from a ready supermarket order', function (): void {
    $customer = User::factory()->create();
    $order = SmOrderFactory::new()->readyForPickup()->create(['customer_id' => $customer->id]);
    DeliveryOrderFactory::new()->create([
        'source_type' => DeliveryOrderCreationService::SOURCE_SUPERMARKET_ORDER,
        'source_id' => $order->id,
        'status' => DeliveryOrderStatus::Stopped->value,
        'stop_reason' => 'No eligible drivers',
    ]);

    $payload = app(UserOrderHubService::class)->show($customer->id, 'supermarket', $order->id);

    expect($payload['status'])->toBe('ready_for_pickup')
        ->and($payload['deliverySummary']['status'])->toBe('stopped')
        ->and($payload['deliverySummary']['statusLabelAr'])->toBe('متوقف')
        ->and($payload['deliverySummary']['stopReason'])->toBe('No eligible drivers');
});

it('localizes the supermarket readiness stage in the merchant summary', function (): void {
    $order = SmOrderFactory::new()->readyForPickup()->create();

    expect(DeliveryPresentation::merchantSummary($order)['statusLabelAr'])
        ->toBe('جاهز للاستلام');
});
