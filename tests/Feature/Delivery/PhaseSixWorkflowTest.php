<?php

declare(strict_types=1);

use App\Enums\DisputeResolution;
use App\Enums\DisputeStatus;
use App\Models\Dispute;
use Illuminate\Support\Facades\Notification;
use Modules\Delivery\Enums\DeliveryFinancialTransactionType;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Jobs\RecoverDriverTrustScoreJob;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Notifications\DeliveryCanonicalNotification;
use Modules\Delivery\Services\DeliveryNotificationService;
use Modules\Delivery\Services\DeliveryOrderService;
use Modules\Delivery\Services\DeliveryReportService;

it('notifies company and driver when a delivery dispute is opened via observer', function (): void {
    Notification::fake();

    $driver = DeliveryDriver::factory()->create();
    $order = DeliveryOrder::factory()->create([
        'company_id' => $driver->company_id,
        'driver_id' => $driver->id,
    ]);

    Dispute::query()->create([
        'booking_type' => 'delivery_order',
        'booking_id' => $order->id,
        'ticket_number' => 'DEL-DSP-OPEN01',
        'description' => 'Late delivery',
        'category' => 'poor_quality',
        'status' => DisputeStatus::Open->value,
    ]);

    expect($driver->fresh()->open_disputes_count)->toBe(1);

    Notification::assertSentTo(
        $driver->user,
        DeliveryCanonicalNotification::class,
        fn (DeliveryCanonicalNotification $notification): bool => $notification->toArray($driver->user)['canonical_type'] === 'delivery.dispute.opened',
    );
});

it('sends rejected dispute notifications and clears open dispute count', function (): void {
    Notification::fake();

    $driver = DeliveryDriver::factory()->create(['open_disputes_count' => 1]);
    $order = DeliveryOrder::factory()->create([
        'company_id' => $driver->company_id,
        'driver_id' => $driver->id,
    ]);

    $dispute = Dispute::query()->create([
        'booking_type' => 'delivery_order',
        'booking_id' => $order->id,
        'ticket_number' => 'DEL-DSP-REJ01',
        'description' => 'Invalid claim',
        'category' => 'other',
        'status' => DisputeStatus::Open->value,
    ]);

    $dispute->update(['status' => DisputeStatus::Rejected->value]);

    expect($driver->fresh()->open_disputes_count)->toBe(0);

    Notification::assertSentTo(
        $driver->user,
        DeliveryCanonicalNotification::class,
        fn (DeliveryCanonicalNotification $notification): bool => $notification->toArray($driver->user)['canonical_type'] === 'delivery.dispute.rejected',
    );
});

it('records a dispute penalty debit when configured and dispute resolves with worker penalty', function (): void {
    config([
        'delivery.financial.dispute_penalty_amount' => 2500,
        'delivery.trust.dispute_penalty' => 5,
    ]);

    $company = DeliveryCompany::factory()->create(['financial_limit' => 100000]);
    $driver = DeliveryDriver::factory()->create(['company_id' => $company->id, 'trust_score' => 100]);
    $order = DeliveryOrder::factory()->create([
        'company_id' => $company->id,
        'driver_id' => $driver->id,
    ]);

    $dispute = Dispute::query()->create([
        'booking_type' => 'delivery_order',
        'booking_id' => $order->id,
        'ticket_number' => 'DEL-DSP-FIN01',
        'description' => 'Damaged goods',
        'category' => 'property_damage',
        'status' => DisputeStatus::UnderReview->value,
    ]);

    $dispute->update([
        'status' => DisputeStatus::Resolved->value,
        'resolution' => DisputeResolution::WorkerPenalty->value,
    ]);

    $this->assertDatabaseHas('delivery_financial_transactions', [
        'transaction_type' => DeliveryFinancialTransactionType::DisputePenaltyDebit->value,
        'amount' => 2500,
    ]);

    expect($driver->fresh()->trust_score)->toBe(95);
});

it('aggregates delivery report metrics for a company', function (): void {
    $company = DeliveryCompany::factory()->create();
    DeliveryDriver::factory()->available()->create(['company_id' => $company->id]);
    DeliveryDriver::factory()->create([
        'company_id' => $company->id,
        'availability_status' => 'offline',
        'is_suspended' => true,
    ]);

    DeliveryOrder::factory()->create([
        'company_id' => $company->id,
        'status' => DeliveryOrderStatus::Completed->value,
        'completed_at' => now(),
    ]);

    DeliveryOrder::factory()->create([
        'company_id' => $company->id,
        'status' => DeliveryOrderStatus::Stopped->value,
    ]);

    $orderForDispute = DeliveryOrder::factory()->create(['company_id' => $company->id]);

    Dispute::query()->create([
        'booking_type' => 'delivery_order',
        'booking_id' => $orderForDispute->id,
        'ticket_number' => 'DEL-DSP-RPT01',
        'description' => 'Report dispute',
        'category' => 'other',
        'status' => DisputeStatus::Open->value,
    ]);

    $summary = app(DeliveryReportService::class)->summary($company, 30);

    expect($summary['statusCounts'])->toHaveKey(DeliveryOrderStatus::Completed->value)
        ->and($summary['driverAvailability']['total'])->toBe(2)
        ->and($summary['disputesCount'])->toBe(1)
        ->and($summary['openDisputesCount'])->toBe(1)
        ->and($summary['financial'])->toHaveKeys(['currentBalance', 'financialLimit', 'currency', 'feesInPeriod', 'isSuspended']);
});

it('filters delivery notifications for company users', function (): void {
    $company = DeliveryCompany::factory()->create();
    $owner = $company->owner()->firstOrFail();

    $owner->notify(new DeliveryCanonicalNotification(
        canonicalType: 'delivery.order.completed',
        templateContext: ['order_number' => 'DEL-001'],
        extraData: ['orderId' => 1],
    ));

    $owner->notify(new class extends Illuminate\Notifications\Notification
    {
        public function via(object $notifiable): array
        {
            return ['database'];
        }

        /** @return array<string, mixed> */
        public function toArray(object $notifiable): array
        {
            return ['type' => 'other_module'];
        }
    });

    $feed = app(DeliveryNotificationService::class)->feedForUser($owner);

    expect($feed)->toHaveCount(1)
        ->and($feed->first()['data']['canonicalType'] ?? null)
        ->toBe('delivery.order.completed');
});

it('sends lifecycle notifications when driver progresses an order', function (): void {
    Notification::fake();

    $driver = DeliveryDriver::factory()->create();
    $order = DeliveryOrder::factory()->create([
        'company_id' => $driver->company_id,
        'driver_id' => $driver->id,
        'status' => DeliveryOrderStatus::Accepted->value,
    ]);

    $service = app(DeliveryOrderService::class);

    $service->start($order, $driver->id);
    $service->pickup($order->fresh(), $driver->id);
    $service->deliver($order->fresh(), $driver->id);

    Notification::assertSentTo(
        $driver->user,
        DeliveryCanonicalNotification::class,
        fn (DeliveryCanonicalNotification $notification): bool => in_array(
            $notification->toArray($driver->user)['canonical_type'] ?? '',
            ['delivery.order.started', 'delivery.order.picked_up', 'delivery.order.delivered', 'delivery.order.completed'],
            true,
        ),
    );
});

it('recovers trust scores for eligible drivers via scheduled job', function (): void {
    Notification::fake();

    $eligible = DeliveryDriver::factory()->create([
        'trust_score' => 90,
        'open_disputes_count' => 0,
        'is_active' => true,
        'is_suspended' => false,
    ]);

    DeliveryDriver::factory()->create([
        'trust_score' => 80,
        'open_disputes_count' => 2,
    ]);

    RecoverDriverTrustScoreJob::dispatchSync();

    expect($eligible->fresh()->trust_score)->toBe(91);
});
