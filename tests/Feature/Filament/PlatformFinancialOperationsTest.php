<?php

declare(strict_types=1);

use App\Filament\Pages\PlatformFinancialOperations;
use App\Models\RestaurantFinancialSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryFinancialAccount;
use Modules\Delivery\Models\DeliveryFinancialTransaction;
use Modules\Resturants\Models\Order;
use Modules\Supermarket\Enums\SmCommissionType;
use Modules\Supermarket\Models\SmCommissionRule;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmStore;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $role = Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole($role);
    $this->actingAs($admin);
});

it('renders platform financial operations from backend source-of-truth values', function (): void {
    RestaurantFinancialSetting::query()->create([
        'commission_type' => 'percent',
        'commission_value' => 12.5,
    ]);

    Order::factory()->create([
        'status' => 'completed',
        'subtotal' => 1000,
        'discount_amount' => 0,
        'service_fee' => 50,
        'total_amount' => 1050,
        'completed_at' => now(),
    ]);

    $store = SmStore::factory()->create();
    SmCommissionRule::query()->create([
        'store_id' => $store->id,
        'commission_type' => SmCommissionType::Percentage->value,
        'value' => 10,
        'is_active' => true,
        'is_default' => true,
    ]);

    $smOrder = SmOrder::factory()->create([
        'store_id' => $store->id,
        'status' => 'completed',
        'subtotal' => 2000,
        'discount_amount' => 0,
        'service_fee' => 100,
        'total_amount' => 2100,
    ])->refresh();

    $company = DeliveryCompany::factory()->create(['name' => 'Finance Delivery']);
    $account = DeliveryFinancialAccount::query()->create([
        'owner_type' => DeliveryCompany::class,
        'owner_id' => $company->id,
        'currency' => 'SYP',
        'current_balance' => 300,
        'financial_limit' => 1000,
        'is_suspended' => false,
    ]);

    DeliveryFinancialTransaction::query()->create([
        'account_id' => $account->id,
        'transaction_type' => 'order_fee_debit',
        'direction' => 'debit',
        'amount' => 500,
        'balance_before' => 0,
        'balance_after' => 500,
        'note' => 'test debit',
    ]);
    DeliveryFinancialTransaction::query()->create([
        'account_id' => $account->id,
        'transaction_type' => 'collection_credit',
        'direction' => 'credit',
        'amount' => 200,
        'balance_before' => 500,
        'balance_after' => 300,
        'note' => 'test credit',
    ]);

    $page = Livewire::test(PlatformFinancialOperations::class);
    $data = $page->instance()->getViewData();

    expect($data['restaurant']['customer_total'])->toBe(1050.0)
        ->and($data['restaurant']['service_fees'])->toBe(50.0)
        ->and($data['restaurant']['commission'])->toBe(125.0)
        ->and($data['restaurant']['merchant_net'])->toBe(875.0)
        ->and($data['restaurant']['platform_net'])->toBe(175.0)
        ->and($data['restaurant']['unsnapshotted'])->toBe(0)
        ->and($data['restaurant']['commission_setting_type'])->toBe('نسبة مئوية')
        ->and($data['restaurant']['commission_setting_value'])->toBe(12.5)
        ->and($data['restaurant']['settlement_status'])->toContain('snapshot')
        ->and($data['supermarket']['commission'])->toBe(200.0)
        ->and($data['supermarket']['store_net'])->toBe(1800.0)
        ->and($data['supermarket']['unsnapshotted'])->toBe(0)
        ->and($data['delivery']['debits'])->toBe(500.0)
        ->and($data['delivery']['credits'])->toBe(200.0)
        ->and($data['delivery']['company_balance'])->toBe(300.0)
        ->and((float) $smOrder->commission_amount)->toBe(200.0);

    $this->get(PlatformFinancialOperations::getUrl([], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('العمليات المالية للمنصة')
        ->assertSee('إعداد العمولة الحالي للطلبات الجديدة')
        ->assertSee('نسبة مئوية / 12.50')
        ->assertSee('Finance Delivery');
});
