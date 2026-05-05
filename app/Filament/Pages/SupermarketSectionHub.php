<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\ResolvesSupermarketNavigationGroup;
use App\Filament\Resources\MasterProductCategories\MasterProductCategoryResource;
use App\Filament\Resources\MasterProducts\MasterProductResource;
use App\Filament\Resources\SmCategories\SmCategoryResource;
use App\Filament\Resources\SmCoupons\SmCouponResource;
use App\Filament\Resources\SmOffers\SmOfferResource;
use App\Filament\Resources\SmOrderDisputes\SmOrderDisputeResource;
use App\Filament\Resources\SmOrders\SmOrderResource;
use App\Filament\Resources\SmProducts\SmProductResource;
use App\Filament\Resources\SmStoreDocuments\SmStoreDocumentResource;
use App\Filament\Resources\SmStores\SmStoreResource;
use App\Filament\Resources\SmStoreTrustLogs\SmStoreTrustLogResource;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Modules\Supermarket\Enums\SmDisputeStatus;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Models\SmCoupon;
use Modules\Supermarket\Models\SmOffer;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmOrderDispute;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Models\SmStore;
use Modules\Supermarket\Models\SmStoreDocument;
use Modules\Supermarket\Services\ReportService;

final class SupermarketSectionHub extends Page
{
    use ResolvesSupermarketNavigationGroup;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = null;

    protected static ?string $title = null;

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
        $now = CarbonImmutable::now();
        $dashboard = app(ReportService::class)->getDashboardData();

        $activityMetrics = $dashboard['activity_metrics'] ?? [];
        $salesSummary = $dashboard['sales_summary'] ?? [];
        $operationalAlerts = $dashboard['operational_alerts'] ?? [];
        $queueCounts = $dashboard['queue_counts'] ?? [];

        $pendingDocuments = SmStoreDocument::query()
            ->where('verification_status', 'pending')
            ->with('store:id,name')
            ->latest('created_at')
            ->limit(6)
            ->get();

        $openDisputes = SmOrderDispute::query()
            ->whereIn('status', [
                SmDisputeStatus::Open->value,
                SmDisputeStatus::UnderReview->value,
            ])
            ->with(['order:id,order_number,store_id', 'order.store:id,name'])
            ->latest('created_at')
            ->limit(6)
            ->get();

        $suspendedStores = SmStore::query()
            ->whereNotNull('suspension_until')
            ->where('suspension_until', '>', $now)
            ->orderBy('suspension_until')
            ->limit(6)
            ->get(['id', 'name', 'suspension_until']);

        $pendingPickupOrders = SmOrder::query()
            ->where('status', SmOrderStatus::ReadyForPickup->value)
            ->with('store:id,name')
            ->latest('ready_for_pickup_at')
            ->limit(6)
            ->get();

        $lowStockProducts = SmProduct::query()
            ->where('is_available', true)
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->with('store:id,name')
            ->orderBy('stock_quantity')
            ->limit(6)
            ->get();

        $expiringOffers = SmOffer::query()
            ->where('is_active', true)
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [$now, $now->addDays(3)])
            ->with('store:id,name')
            ->orderBy('ends_at')
            ->limit(6)
            ->get();

        $expiringCoupons = SmCoupon::query()
            ->where('is_active', true)
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [$now, $now->addDays(3)])
            ->with('store:id,name')
            ->orderBy('ends_at')
            ->limit(6)
            ->get();

        $queueEmpty = __('supermarket_admin.queues.empty');

        $attentionComplianceQueues = [
            $this->makeQueue(
                __('supermarket_admin.queues.pending_documents'),
                (int) ($queueCounts['pending_documents'] ?? 0),
                $pendingDocuments->map(function (SmStoreDocument $document): array {
                    $documentType = $document->document_type?->value;

                    return [
                        'label' => ($document->store?->name ?? __('supermarket_admin.labels.unknown_store')) . ' - ' . ($documentType ? __('supermarket_admin.enums.document_type.' . $documentType) : '—'),
                        'meta' => $document->created_at?->diffForHumans() ?? '—',
                        'url' => SmStoreDocumentResource::getUrl('edit', ['record' => $document]),
                    ];
                })->all(),
                $queueEmpty,
            ),
            $this->makeQueue(
                __('supermarket_admin.queues.open_disputes'),
                (int) ($queueCounts['open_disputes'] ?? 0),
                $openDisputes->map(fn(SmOrderDispute $dispute): array => [
                    'label' => $dispute->ticket_number . ' - ' . ($dispute->order?->order_number ?? '—'),
                    'meta' => $dispute->order?->store?->name ?? __('supermarket_admin.labels.unknown_store'),
                    'url' => SmOrderDisputeResource::getUrl('edit', ['record' => $dispute]),
                ])->all(),
                $queueEmpty,
            ),
            $this->makeQueue(
                __('supermarket_admin.queues.suspended_stores'),
                (int) ($queueCounts['suspended_stores'] ?? 0),
                $suspendedStores->map(fn(SmStore $store): array => [
                    'label' => $store->name,
                    'meta' => __('supermarket_admin.queues.suspended_until', ['date' => $store->suspension_until?->format('Y-m-d H:i') ?? '—']),
                    'url' => SmStoreResource::getUrl('edit', ['record' => $store]),
                ])->all(),
                $queueEmpty,
            ),
        ];

        $attentionFulfillmentQueues = [
            $this->makeQueue(
                __('supermarket_admin.queues.pending_pickup_orders'),
                (int) ($queueCounts['pending_pickup_orders'] ?? 0),
                $pendingPickupOrders->map(fn(SmOrder $order): array => [
                    'label' => $order->order_number . ' - ' . ($order->store?->name ?? __('supermarket_admin.labels.unknown_store')),
                    'meta' => $order->ready_for_pickup_at?->diffForHumans() ?? ($order->created_at?->diffForHumans() ?? '—'),
                    'url' => SmOrderResource::getUrl('view', ['record' => $order]),
                ])->all(),
                $queueEmpty,
            ),
        ];

        $attentionCatalogQueues = [
            $this->makeQueue(
                __('supermarket_admin.queues.low_stock_products'),
                (int) ($queueCounts['low_stock_products'] ?? 0),
                $lowStockProducts->map(fn(SmProduct $product): array => [
                    'label' => $product->name . ' - ' . ($product->store?->name ?? __('supermarket_admin.labels.unknown_store')),
                    'meta' => __('supermarket_admin.queues.stock_value', [
                        'stock' => (int) ($product->stock_quantity ?? 0),
                        'threshold' => (int) ($product->low_stock_threshold ?? 0),
                    ]),
                    'url' => SmProductResource::getUrl('edit', ['record' => $product]),
                ])->all(),
                $queueEmpty,
            ),
            $this->makeQueue(
                __('supermarket_admin.queues.expiring_promotions'),
                (int) ($queueCounts['expiring_promotions'] ?? 0),
                $expiringOffers->map(fn(SmOffer $offer): array => [
                    'label' => __('supermarket_admin.queues.offer_label', ['name' => $offer->name]),
                    'meta' => ($offer->store?->name ?? __('supermarket_admin.labels.unknown_store')) . ' - ' . ($offer->ends_at?->format('Y-m-d H:i') ?? '—'),
                    'url' => SmOfferResource::getUrl('edit', ['record' => $offer]),
                ])->concat(
                    $expiringCoupons->map(fn(SmCoupon $coupon): array => [
                        'label' => __('supermarket_admin.queues.coupon_label', ['code' => $coupon->code]),
                        'meta' => ($coupon->store?->name ?? __('supermarket_admin.labels.unknown_store')) . ' - ' . ($coupon->ends_at?->format('Y-m-d H:i') ?? '—'),
                        'url' => SmCouponResource::getUrl('edit', ['record' => $coupon]),
                    ])
                )->take(6)->values()->all(),
                $queueEmpty,
            ),
        ];

        return [
            'overviewKpis' => [
                [
                    'label' => __('supermarket_admin.metrics.total_stores'),
                    'value' => (int) ($activityMetrics['total_stores'] ?? 0),
                    'hint' => __('supermarket_admin.metrics.active_stores_hint', ['count' => (int) ($activityMetrics['active_stores'] ?? 0)]),
                ],
                [
                    'label' => __('supermarket_admin.metrics.total_orders'),
                    'value' => (int) ($activityMetrics['total_orders'] ?? 0),
                    'hint' => __('supermarket_admin.metrics.pending_pickup_hint', ['count' => (int) ($activityMetrics['pending_pickup_orders'] ?? 0)]),
                ],
                [
                    'label' => __('supermarket_admin.metrics.open_disputes'),
                    'value' => (int) ($operationalAlerts['open_disputes_count'] ?? 0),
                    'hint' => __('supermarket_admin.metrics.high_cancellation_hint', ['count' => (int) ($operationalAlerts['high_cancellation_stores_count'] ?? 0)]),
                ],
                [
                    'label' => __('supermarket_admin.metrics.low_stock_products'),
                    'value' => (int) ($operationalAlerts['low_stock_products_count'] ?? 0),
                    'hint' => __('supermarket_admin.metrics.week_sales_hint', ['amount' => $this->formatCurrency($salesSummary['this_week'] ?? 0)]),
                ],
            ],
            'workflowSections' => [
                [
                    'title' => __('supermarket_admin.flow.governance.title'),
                    'description' => __('supermarket_admin.flow.governance.description'),
                    'links' => [
                        ['label' => __('supermarket_admin.hub.stores'), 'url' => SmStoreResource::getUrl('index')],
                        ['label' => __('supermarket_admin.hub.documents'), 'url' => SmStoreDocumentResource::getUrl('index')],
                    ],
                ],
                [
                    'title' => __('supermarket_admin.flow.catalog.title'),
                    'description' => __('supermarket_admin.flow.catalog.description'),
                    'links' => [
                        ['label' => __('supermarket_admin.hub.master_product_categories'), 'url' => MasterProductCategoryResource::getUrl('index')],
                        ['label' => __('supermarket_admin.hub.master_products'), 'url' => MasterProductResource::getUrl('index')],
                        ['label' => __('supermarket_admin.hub.categories'), 'url' => SmCategoryResource::getUrl('index')],
                        ['label' => __('supermarket_admin.hub.products'), 'url' => SmProductResource::getUrl('index')],
                        ['label' => __('supermarket_admin.hub.offers'), 'url' => SmOfferResource::getUrl('index')],
                        ['label' => __('supermarket_admin.hub.coupons'), 'url' => SmCouponResource::getUrl('index')],
                    ],
                ],
                [
                    'title' => __('supermarket_admin.flow.operations.title'),
                    'description' => __('supermarket_admin.flow.operations.description'),
                    'links' => [
                        ['label' => __('supermarket_admin.hub.orders'), 'url' => SmOrderResource::getUrl('index'), 'badge' => __('supermarket_admin.labels.read_only')],
                        ['label' => __('supermarket_admin.hub.disputes'), 'url' => SmOrderDisputeResource::getUrl('index')],
                    ],
                ],
            ],
            'referenceLinks' => [
                ['label' => __('supermarket_admin.hub.trust_logs'), 'url' => SmStoreTrustLogResource::getUrl('index'), 'badge' => __('supermarket_admin.labels.read_only')],
                ['label' => __('supermarket_admin.hub.daily_stats'), 'url' => SupermarketStatsPage::getUrl()],
            ],
            'attentionGroups' => [
                [
                    'title' => __('supermarket_admin.attention.compliance_title'),
                    'description' => __('supermarket_admin.attention.compliance_description'),
                    'queues' => $attentionComplianceQueues,
                ],
                [
                    'title' => __('supermarket_admin.attention.fulfillment_title'),
                    'description' => __('supermarket_admin.attention.fulfillment_description'),
                    'queues' => $attentionFulfillmentQueues,
                ],
                [
                    'title' => __('supermarket_admin.attention.catalog_title'),
                    'description' => __('supermarket_admin.attention.catalog_description'),
                    'queues' => $attentionCatalogQueues,
                ],
            ],
            'recentActivity' => $dashboard['recent_activity'] ?? [],
        ];
    }

    /**
     * @param  list<array{label: string, meta: string, url: string}>  $items
     * @return array{title: string, count: int, items: list<array{label: string, meta: string, url: string}>, emptyMessage: string}
     */
    private function makeQueue(string $title, int $count, array $items, string $emptyMessage): array
    {
        return [
            'title' => $title,
            'count' => $count,
            'items' => $items,
            'emptyMessage' => $emptyMessage,
        ];
    }

    private function formatCurrency(float|int|string $amount): string
    {
        return number_format((float) $amount, 2);
    }
}
