<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Supermarket\Enums\SmCommissionType;
use Modules\Supermarket\Models\SmCommissionRule;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmStore;
use Modules\Supermarket\Services\ReportService;

uses(RefreshDatabase::class);

it('snapshots the eligible supermarket commission rule when an order is created', function (): void {
    $store = SmStore::factory()->create();

    $default = SmCommissionRule::query()->create([
        'store_id' => $store->id,
        'commission_type' => SmCommissionType::Percentage->value,
        'value' => 5,
        'is_active' => true,
        'is_default' => true,
    ]);

    $tier = SmCommissionRule::query()->create([
        'store_id' => $store->id,
        'commission_type' => SmCommissionType::Percentage->value,
        'value' => 7.5,
        'min_order_amount' => 1000,
        'is_active' => true,
        'is_default' => false,
    ]);

    $order = SmOrder::factory()->create([
        'store_id' => $store->id,
        'subtotal' => 2000,
        'discount_amount' => 300,
        'service_fee' => 100,
        'total_amount' => 1800,
    ])->refresh();

    expect($order->commission_rule_id)->toBe($tier->id)
        ->and((float) $order->commission_base_amount)->toBe(2000.0)
        ->and((float) $order->commission_amount)->toBe(150.0)
        ->and((float) $order->store_net_amount)->toBe(1850.0)
        ->and($order->commission_snapshot['base'])->toBe('merchant_gross_amount')
        ->and($order->commission_snapshot['rule_id'])->toBe($tier->id)
        ->and($default->id)->not->toBe($tier->id);
});

it('applies fixed commission caps and falls back to the default eligible rule', function (): void {
    $store = SmStore::factory()->create();

    $rule = SmCommissionRule::query()->create([
        'store_id' => $store->id,
        'commission_type' => SmCommissionType::Fixed->value,
        'value' => 180,
        'max_commission_amount' => 100,
        'is_active' => true,
        'is_default' => true,
    ]);

    $order = SmOrder::factory()->create([
        'store_id' => $store->id,
        'subtotal' => 900,
        'discount_amount' => 0,
        'service_fee' => 0,
        'total_amount' => 900,
    ])->refresh();

    expect($order->commission_rule_id)->toBe($rule->id)
        ->and((float) $order->commission_amount)->toBe(100.0)
        ->and((float) $order->store_net_amount)->toBe(800.0);
});

it('reports snapshotted commissions and explicitly counts legacy unsnapshotted orders', function (): void {
    Carbon::setTestNow('2026-09-27 12:00:00');

    $store = SmStore::factory()->create();

    SmCommissionRule::query()->create([
        'store_id' => $store->id,
        'commission_type' => SmCommissionType::Percentage->value,
        'value' => 10,
        'is_active' => true,
        'is_default' => true,
    ]);

    $snapshotted = SmOrder::factory()->create([
        'store_id' => $store->id,
        'subtotal' => 1000,
        'discount_amount' => 0,
        'service_fee' => 50,
        'total_amount' => 1050,
    ])->refresh();

    $legacy = SmOrder::factory()->create([
        'store_id' => $store->id,
        'subtotal' => 500,
        'discount_amount' => 0,
        'service_fee' => 25,
        'total_amount' => 525,
    ]);
    $legacy->forceFill([
        'commission_rule_id' => null,
        'commission_base_amount' => null,
        'commission_amount' => null,
        'store_net_amount' => null,
        'merchant_gross_amount' => null,
        'merchant_net_amount' => null,
        'platform_gross_revenue' => null,
        'platform_coupon_cost' => null,
        'platform_net_revenue' => null,
        'financial_snapshot' => null,
        'commission_snapshot' => null,
    ])->saveQuietly();

    $report = app(ReportService::class)->getFinancialReport(
        Carbon::parse('2026-09-27 00:00:00'),
        Carbon::parse('2026-09-27 23:59:59'),
        $store->id,
    );

    expect((float) $snapshotted->commission_amount)->toBe(100.0)
        ->and($report['overview']['total_commissions'])->toBe(100.0)
        ->and($report['overview']['store_net_payable'])->toBe(900.0)
        ->and($report['overview']['unsnapshotted_orders'])->toBe(1)
        ->and($report['by_store'][0]['commission_deducted'])->toBe(100.0)
        ->and($report['by_store'][0]['unsnapshotted_orders'])->toBe(1);

    Carbon::setTestNow();
});
