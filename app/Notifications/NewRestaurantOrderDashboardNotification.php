<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Resources\Orders\OrderResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Modules\Resturants\Models\Order;

final class NewRestaurantOrderDashboardNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly Order $order) {}

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
                ->title(__('restaurant_admin.order_notification.created_title'))
                ->body(__('restaurant_admin.order_notification.created_body', [
                    'order' => $this->order->order_number ?? (string) $this->order->id,
                ]))
                ->icon('heroicon-o-clipboard-document-list')
                ->info()
                ->actions([
                    Action::make('view')
                        ->label(__('restaurant_admin.order_notification.view'))
                        ->url(OrderResource::getUrl('view', ['record' => $this->order]))
                        ->markAsRead(),
                ])
                ->getDatabaseMessage(),
            ['sound_type' => 'notify'],
        );
    }
}
