<?php

declare(strict_types=1);

namespace App\Notifications;

use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Modules\Cleaning\Models\CleaningMaterial;

final class CleaningMaterialLowStockDashboardNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly CleaningMaterial $material) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $stock = number_format((float) $this->material->stock_quantity, 3, '.', '');
        $threshold = number_format((float) $this->material->low_stock_threshold, 3, '.', '');

        return array_merge(
            FilamentNotification::make()
                ->title('مخزون مادة تنظيف منخفض')
                ->body("المادة {$this->material->name} وصلت إلى {$stock}، وحد التنبيه {$threshold}.")
                ->icon('heroicon-o-exclamation-triangle')
                ->warning()
                ->getDatabaseMessage(),
            [
                'sound_type' => 'notify',
                'cleaning_material_id' => (int) $this->material->id,
                'stock_quantity' => (float) $this->material->stock_quantity,
                'low_stock_threshold' => (float) $this->material->low_stock_threshold,
            ],
        );
    }
}
