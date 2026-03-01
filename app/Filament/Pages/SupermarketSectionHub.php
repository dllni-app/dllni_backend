<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\SmCoupons\SmCouponResource;
use App\Filament\Resources\SmOffers\SmOfferResource;
use App\Filament\Resources\SmOrderDisputes\SmOrderDisputeResource;
use App\Filament\Resources\SmOrders\SmOrderResource;
use App\Filament\Resources\SmProducts\SmProductResource;
use App\Filament\Resources\SmStoreDailyStats\SmStoreDailyStatResource;
use App\Filament\Resources\SmStoreDocuments\SmStoreDocumentResource;
use App\Filament\Resources\SmStores\SmStoreResource;
use App\Filament\Resources\SmStoreTrustLogs\SmStoreTrustLogResource;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

final class SupermarketSectionHub extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $navigationLabel = null;

    protected static ?string $title = null;

    protected static string|UnitEnum|null $navigationGroup = 'قسم المتاجر';

    protected static ?int $navigationSort = 1;

    protected static bool $shouldRegisterNavigation = true;

    protected string $view = 'filament.supermarket-admin.pages.supermarket-section-hub';

    public static function getNavigationLabel(): string
    {
        return __('supermarket_admin.hub.title');
    }

    public function getTitle(): string
    {
        return __('supermarket_admin.hub.title');
    }

    public function getViewData(): array
    {
        return [
            'cards' => [
                ['label' => __('supermarket_admin.hub.stores'), 'url' => SmStoreResource::getUrl('index')],
                ['label' => __('supermarket_admin.hub.documents'), 'url' => SmStoreDocumentResource::getUrl('index')],
                ['label' => __('supermarket_admin.hub.trust_logs'), 'url' => SmStoreTrustLogResource::getUrl('index')],
                ['label' => __('supermarket_admin.hub.products'), 'url' => SmProductResource::getUrl('index')],
                ['label' => __('supermarket_admin.hub.offers'), 'url' => SmOfferResource::getUrl('index')],
                ['label' => __('supermarket_admin.hub.coupons'), 'url' => SmCouponResource::getUrl('index')],
                ['label' => __('supermarket_admin.hub.orders'), 'url' => SmOrderResource::getUrl('index')],
                ['label' => __('supermarket_admin.hub.disputes'), 'url' => SmOrderDisputeResource::getUrl('index')],
                ['label' => __('supermarket_admin.hub.daily_stats'), 'url' => SmStoreDailyStatResource::getUrl('index')],
            ],
        ];
    }
}
