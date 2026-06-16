<?php

declare(strict_types=1);

namespace App\Filament\Company\Pages;

use App\Enums\PermissionGroup;
use App\Filament\Company\Resources\DeliveryOrders\DeliveryOrderResource;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Modules\Delivery\Services\DeliveryCompanyContextService;
use Modules\Delivery\Services\DeliveryNotificationService;

final class DeliveryNotificationsPage extends Page
{
    public string $filter = 'all';

    /** @var Collection<int, array<string, mixed>> */
    public Collection $notifications;

    public int $unreadCount = 0;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.company.pages.delivery-notifications';

    public static function getNavigationGroup(): ?string
    {
        return __('delivery_company.nav_groups.notifications');
    }

    public static function getNavigationLabel(): string
    {
        return __('delivery_company.notifications.nav_label');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(PermissionGroup::DeliveryOrders->value.'.view') ?? false;
    }

    public function mount(): void
    {
        $this->loadNotifications();
    }

    public function updatedFilter(): void
    {
        $this->loadNotifications();
    }

    public function markAsRead(string $notificationId): void
    {
        app(DeliveryNotificationService::class)->markAsRead(auth()->user(), $notificationId);
        $this->loadNotifications();
    }

    public function markAllAsRead(): void
    {
        app(DeliveryNotificationService::class)->markAllAsRead(auth()->user());
        $this->loadNotifications();
    }

    public function orderUrl(?int $orderId): ?string
    {
        if ($orderId === null) {
            return null;
        }

        return DeliveryOrderResource::getUrl('view', ['record' => $orderId], panel: 'company');
    }

    public function getTitle(): string|Htmlable
    {
        return __('delivery_company.notifications.title');
    }

    private function loadNotifications(): void
    {
        $service = app(DeliveryNotificationService::class);
        $user = auth()->user();

        app(DeliveryCompanyContextService::class)->resolveFromUser($user);

        $this->notifications = $service->feedForUser(
            user: $user,
            unreadOnly: $this->filter === 'unread',
        );
        $this->unreadCount = $service->unreadCountForUser($user);
    }
}
