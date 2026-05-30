<?php

declare(strict_types=1);

use App\Enums\DisputeResolution;
use App\Enums\DisputeStatus;
use App\Models\Dispute;
use Illuminate\Support\Facades\Notification;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Notifications\DeliveryCanonicalNotification;
use Modules\Delivery\Services\DriverTrustService;

it('applies a dispute penalty and writes a trust log', function (): void {
    Notification::fake();

    $driver = DeliveryDriver::factory()->create(['trust_score' => 100]);
    $dispute = Dispute::query()->create([
        'booking_type' => 'delivery_order',
        'booking_id' => DeliveryOrder::factory()->create([
            'company_id' => $driver->company_id,
            'driver_id' => $driver->id,
        ])->id,
        'ticket_number' => 'DEL-DSP-TEST01',
        'description' => 'Test dispute',
        'category' => 'poor_quality',
        'status' => DisputeStatus::Open->value,
    ]);

    app(DriverTrustService::class)->applyDisputePenalty($driver, $dispute, 15);

    expect($driver->fresh()->trust_score)->toBe(85);
    $this->assertDatabaseHas('delivery_driver_trust_logs', [
        'driver_id' => $driver->id,
        'reason' => 'dispute_penalty',
        'score_delta' => -15,
        'score_after' => 85,
        'related_dispute_id' => $dispute->id,
    ]);

    Notification::assertSentTo($driver->user, DeliveryCanonicalNotification::class);
});

it('recovers trust score up to the configured maximum', function (): void {
    Notification::fake();

    $driver = DeliveryDriver::factory()->create([
        'trust_score' => 98,
        'open_disputes_count' => 0,
    ]);

    app(DriverTrustService::class)->recoverScore($driver, 5);

    expect($driver->fresh()->trust_score)->toBe(100);
    $this->assertDatabaseHas('delivery_driver_trust_logs', [
        'driver_id' => $driver->id,
        'reason' => 'scheduled_recovery',
        'score_after' => 100,
    ]);
});

it('tracks open dispute counts when a delivery dispute opens and closes', function (): void {
    $driver = DeliveryDriver::factory()->create(['open_disputes_count' => 0]);
    $order = DeliveryOrder::factory()->create([
        'company_id' => $driver->company_id,
        'driver_id' => $driver->id,
    ]);

    $dispute = Dispute::query()->create([
        'booking_type' => 'delivery_order',
        'booking_id' => $order->id,
        'ticket_number' => 'DEL-DSP-TEST02',
        'description' => 'Test dispute',
        'category' => 'poor_quality',
        'status' => DisputeStatus::Open->value,
    ]);

    expect($driver->fresh()->open_disputes_count)->toBe(1);

    $dispute->update([
        'status' => DisputeStatus::Closed->value,
        'resolution' => DisputeResolution::WorkerPenalty->value,
    ]);

    expect($driver->fresh()->open_disputes_count)->toBe(0);
    expect($driver->fresh()->trust_score)->toBe(90);
});

it('clears open dispute count when a delivery dispute is rejected', function (): void {
    $driver = DeliveryDriver::factory()->create(['open_disputes_count' => 1]);
    $order = DeliveryOrder::factory()->create([
        'company_id' => $driver->company_id,
        'driver_id' => $driver->id,
    ]);

    $dispute = Dispute::query()->create([
        'booking_type' => 'delivery_order',
        'booking_id' => $order->id,
        'ticket_number' => 'DEL-DSP-TEST03',
        'description' => 'Test dispute',
        'category' => 'other',
        'status' => DisputeStatus::Open->value,
    ]);

    $dispute->update(['status' => DisputeStatus::Rejected->value]);

    expect($driver->fresh()->open_disputes_count)->toBe(0);
    expect($driver->fresh()->trust_score)->toBe(100);
});
