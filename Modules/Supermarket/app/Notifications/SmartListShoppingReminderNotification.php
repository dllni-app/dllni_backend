<?php

declare(strict_types=1);

namespace Modules\Supermarket\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Reminds customers to review a scheduled list. Never claims an order was placed.
 */
final class SmartListShoppingReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $listName,
        private readonly int $listId,
        private readonly int $storeId,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'supermarket.smart_list.reminder',
            'module' => 'supermarket',
            'targetRole' => 'customer',
            'deepLinkTarget' => 'supermarket_shopping_list',
            'shoppingListId' => $this->listId,
            'storeId' => $this->storeId,
            'title' => 'موعد قائمة التسوق',
            'body' => "حان موعد مراجعة قائمة {$this->listName}. افتح القائمة وأكّد الشراء بنفسك.",
            'orderPlaced' => false,
        ];
    }
}
