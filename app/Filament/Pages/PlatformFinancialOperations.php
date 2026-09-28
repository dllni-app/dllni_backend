<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\AuthorizesPlatformAdminResource;
use App\Filament\Resources\DeliveryOrders\DeliveryOrderResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\SmOrders\SmOrderResource;
use App\Models\RestaurantFinancialSetting;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryFinancialAccount;
use Modules\Delivery\Models\DeliveryFinancialTransaction;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Order;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Models\SmOrder;
use UnitEnum;

final class PlatformFinancialOperations extends Page
{
    use AuthorizesPlatformAdminResource;

    public string $dateRange = '30';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'الماليات';

    protected static ?string $navigationLabel = 'ماليات المنصة';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.platform-financial-operations';

    public static function canAccess(): bool
    {
        return self::dashboardAllowed('platform_finance.view');
    }

    public function getTitle(): string
    {
        return 'العمليات المالية للمنصة';
    }

    public function getSubheading(): ?string
    {
        return 'عرض مالي موحد يعتمد على القيم المحفوظة في الـ backend فقط.';
    }

    public function getViewData(): array
    {
        $days = max(7, (int) $this->dateRange);
        $from = now()->subDays($days - 1)->startOfDay();
        $to = now();

        $restaurantCompleted = Order::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('status', OrderStatus::Completed->value);

        $supermarketCompleted = SmOrder::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('status', SmOrderStatus::Completed->value);

        $deliveryTransactions = DeliveryFinancialTransaction::query()
            ->whereBetween('created_at', [$from, $to]);

        $companyAccounts = DeliveryFinancialAccount::query()
            ->where('owner_type', DeliveryCompany::class);

        $restaurantFinancialSetting = RestaurantFinancialSetting::query()
            ->latest('id')
            ->first();

        $restaurantCommissionType = match ($restaurantFinancialSetting?->commission_type) {
            'percent' => 'نسبة مئوية',
            'fixed' => 'مبلغ ثابت',
            default => null,
        };

        return [
            'rangeOptions' => ['7' => '7 أيام', '30' => '30 يوم', '90' => '90 يوم'],
            'restaurant' => [
                'completed_orders' => (clone $restaurantCompleted)->count(),
                'customer_total' => (float) (clone $restaurantCompleted)->sum('total_amount'),
                'subtotal' => (float) (clone $restaurantCompleted)->sum('subtotal'),
                'discounts' => (float) (clone $restaurantCompleted)->sum('discount_amount'),
                'service_fees' => (float) (clone $restaurantCompleted)->sum('service_fee'),
                'commission' => (float) (clone $restaurantCompleted)->sum('commission_amount'),
                'merchant_net' => (float) (clone $restaurantCompleted)->sum('merchant_net_amount'),
                'platform_coupon_cost' => (float) (clone $restaurantCompleted)->sum('platform_coupon_cost'),
                'merchant_coupon_funding' => (float) (clone $restaurantCompleted)->sum('coupon_merchant_funded_amount'),
                'platform_net' => (float) (clone $restaurantCompleted)->sum('platform_net_revenue'),
                'unsnapshotted' => (clone $restaurantCompleted)->whereNull('financial_snapshot')->count(),
                'cancelled_orders' => Order::query()->whereBetween('created_at', [$from, $to])->where('status', OrderStatus::Cancelled->value)->count(),
                'commission_setting_type' => $restaurantCommissionType,
                'commission_setting_value' => $restaurantFinancialSetting !== null ? (float) $restaurantFinancialSetting->commission_value : null,
                'settlement_status' => $restaurantFinancialSetting !== null
                    ? 'الطلبات الجديدة تحفظ settlement snapshot عند الإنشاء، وتثبت تمويل Platform Coupon لحظة الاستخدام.'
                    : 'لا يوجد RestaurantFinancialSetting حالياً؛ الطلبات الجديدة تحفظ snapshot بعمولة صفر حتى يتم تعريف الإعداد.',
                'url' => OrderResource::getUrl('index'),
            ],
            'supermarket' => [
                'completed_orders' => (clone $supermarketCompleted)->count(),
                'customer_total' => (float) (clone $supermarketCompleted)->sum('total_amount'),
                'subtotal' => (float) (clone $supermarketCompleted)->sum('subtotal'),
                'discounts' => (float) (clone $supermarketCompleted)->sum('discount_amount'),
                'service_fees' => (float) (clone $supermarketCompleted)->sum('service_fee'),
                'commission' => (float) (clone $supermarketCompleted)->sum('commission_amount'),
                'store_net' => (float) (clone $supermarketCompleted)->sum('merchant_net_amount'),
                'platform_coupon_cost' => (float) (clone $supermarketCompleted)->sum('platform_coupon_cost'),
                'merchant_coupon_funding' => (float) (clone $supermarketCompleted)->sum('coupon_merchant_funded_amount'),
                'platform_net' => (float) (clone $supermarketCompleted)->sum('platform_net_revenue'),
                'unsnapshotted' => (clone $supermarketCompleted)->whereNull('financial_snapshot')->count(),
                'url' => SmOrderResource::getUrl('index'),
            ],
            'delivery' => [
                'debits' => (float) (clone $deliveryTransactions)->where('direction', 'debit')->sum('amount'),
                'credits' => (float) (clone $deliveryTransactions)->where('direction', 'credit')->sum('amount'),
                'company_balance' => (float) (clone $companyAccounts)->sum('current_balance'),
                'suspended_accounts' => (clone $companyAccounts)->where('is_suspended', true)->count(),
                'url' => DeliveryOrderResource::getUrl('index'),
            ],
            'recentTransactions' => DeliveryFinancialTransaction::query()
                ->with('account.owner')
                ->whereBetween('created_at', [$from, $to])
                ->latest('created_at')
                ->limit(20)
                ->get(),
        ];
    }
}
