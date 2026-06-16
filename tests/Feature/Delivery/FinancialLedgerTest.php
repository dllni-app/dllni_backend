<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Modules\Delivery\Enums\DeliveryFinancialTransactionType;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Enums\DeliverySuspensionReason;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryDriverLocation;
use Modules\Delivery\Models\DeliveryFinancialTransaction;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Notifications\DeliveryCanonicalNotification;
use Modules\Delivery\Services\DeliveryOrderService;
use Modules\Delivery\Services\DriverDispatchService;
use Modules\Delivery\Services\FinancialLedgerService;
use Modules\Delivery\Services\FinancialSuspensionService;

function ledgerOrderPayload(): array
{
    return [
        'customerName' => 'Ledger Customer',
        'customerPhone' => '+963900000099',
        'pickupAddress' => 'Pickup Street',
        'pickupLatitude' => 33.5138,
        'pickupLongitude' => 36.2765,
        'dropoffAddress' => 'Dropoff Street',
        'dropoffLatitude' => 33.5200,
        'dropoffLongitude' => 36.2900,
    ];
}

function createLedgerDriver(DeliveryCompany $company): DeliveryDriver
{
    $driver = DeliveryDriver::factory()->available()->create([
        'company_id' => $company->id,
    ]);

    DeliveryDriverLocation::query()->create([
        'driver_id' => $driver->id,
        'latitude' => 33.5140,
        'longitude' => 36.2767,
        'accuracy' => 5,
        'speed' => 0,
        'heading' => 0,
        'recorded_at' => now(),
    ]);

    return $driver->fresh();
}

function runOrderToDelivered(DeliveryCompany $company, DeliveryDriver $driver): DeliveryOrder
{
    $order = app(DeliveryOrderService::class)->create($company, ledgerOrderPayload());
    app(DriverDispatchService::class)->dispatchByOrderId($order->id);
    $attempt = $order->assignmentAttempts()->firstOrFail();

    Laravel\Sanctum\Sanctum::actingAs($driver->user);
    test()->postJson("/api/v1/delivery/driver/offers/{$attempt->id}/accept")->assertOk();
    test()->postJson("/api/v1/delivery/driver/orders/{$order->id}/start")->assertOk();
    test()->postJson("/api/v1/delivery/driver/orders/{$order->id}/pickup")->assertOk();
    test()->postJson("/api/v1/delivery/driver/orders/{$order->id}/deliver")->assertOk();

    return $order->fresh();
}

it('records an order fee debit when an order is completed', function (): void {
    Notification::fake();

    $company = DeliveryCompany::factory()->create(['financial_limit' => 100000]);
    $driver = createLedgerDriver($company);
    app(FinancialLedgerService::class)->accountForCompany($company);

    $order = runOrderToDelivered($company, $driver);

    expect($order->status)->toBe(DeliveryOrderStatus::Completed->value);
    expect($order->completed_at)->not->toBeNull();

    $this->assertDatabaseHas('delivery_financial_transactions', [
        'transaction_type' => DeliveryFinancialTransactionType::OrderFeeDebit->value,
        'reference_type' => DeliveryOrder::class,
        'reference_id' => $order->id,
        'direction' => 'debit',
        'amount' => $order->delivery_fee,
    ]);

    $account = app(FinancialLedgerService::class)->accountForCompany($company);
    expect((float) $account->current_balance)->toBe((float) $order->delivery_fee);

    Notification::assertSentTo($company->owner, DeliveryCanonicalNotification::class);
});

it('does not duplicate order fee debits for the same order', function (): void {
    $company = DeliveryCompany::factory()->create();
    $driver = createLedgerDriver($company);
    $order = runOrderToDelivered($company, $driver);

    app(FinancialLedgerService::class)->recordOrderFeeDebit($order->fresh());

    expect(DeliveryFinancialTransaction::query()
        ->where('reference_type', DeliveryOrder::class)
        ->where('reference_id', $order->id)
        ->where('transaction_type', DeliveryFinancialTransactionType::OrderFeeDebit->value)
        ->count())->toBe(1);
});

it('financially suspends a company when due balance reaches the limit', function (): void {
    Notification::fake();

    $company = DeliveryCompany::factory()->create(['financial_limit' => 10]);
    $driver = createLedgerDriver($company);
    $account = app(FinancialLedgerService::class)->accountForCompany($company);

    $order = runOrderToDelivered($company, $driver);

    expect((float) $order->delivery_fee)->toBeGreaterThan(10);

    $company->refresh();
    $account->refresh();
    $driver->refresh();

    expect($company->is_suspended)->toBeTrue();
    expect($company->suspension_reason)->toBe(DeliverySuspensionReason::Financial->value);
    expect($account->is_suspended)->toBeTrue();
    expect($driver->is_suspended)->toBeTrue();
    expect($driver->availability_status)->toBe('offline');

    Notification::assertSentTo($company->owner, DeliveryCanonicalNotification::class);
});

it('reactivates a financially suspended company when balance drops below the limit', function (): void {
    Notification::fake();

    $company = DeliveryCompany::factory()->create(['financial_limit' => 10]);
    $account = app(FinancialLedgerService::class)->accountForCompany($company);

    app(FinancialLedgerService::class)->recordTransaction(
        owner: $company,
        transactionType: DeliveryFinancialTransactionType::OrderFeeDebit->value,
        direction: 'debit',
        amount: 15,
    );

    app(FinancialSuspensionService::class)->evaluateCompanyAccount($account->fresh(), $company->fresh());
    expect($company->fresh()->is_suspended)->toBeTrue();

    app(FinancialLedgerService::class)->recordTransaction(
        owner: $company,
        transactionType: DeliveryFinancialTransactionType::CollectionCredit->value,
        direction: 'credit',
        amount: 10,
        note: 'Manual collection',
    );

    app(FinancialSuspensionService::class)->evaluateCompanyAccount($account->fresh(), $company->fresh());

    $company->refresh();
    $account->refresh();

    expect($company->is_suspended)->toBeFalse();
    expect($account->is_suspended)->toBeFalse();
});
