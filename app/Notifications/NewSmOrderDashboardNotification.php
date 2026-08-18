<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Resources\SmOrders\SmOrderResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Modules\Supermarket\Models\SmOrder;

final class NewSmOrderDashboardNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly SmOrder $order) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return array_merge(
            FilamentNotification::make()
                ->title(__('supermarket_admin.order_notification.created_title'))
                ->body(__('supermarket_admin.order_notification.created_body', [
                    'order' => $this->order->order_number ?? (string) $this->order->id,
                ]))
                ->icon('heroicon-o-shopping-cart')
                ->info()
                ->actions([
                    Action::make('view')
                        ->label(__('supermarket_admin.order_notification.view'))
                        ->url(SmOrderResource::getUrl('view', ['record' => $this->order]))
                        ->markAsRead(),
                ])
                ->getDatabaseMessage(),
            ['sound_type' => 'notify'],
        );
    }
}
