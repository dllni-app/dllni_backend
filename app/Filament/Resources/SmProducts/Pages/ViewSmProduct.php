<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmProducts\Pages;

use App\Filament\Resources\SmProducts\SmProductResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Modules\Supermarket\Models\SmProduct;

final class ViewSmProduct extends ViewRecord
{
    protected static string $resource = SmProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('moderate_availability')
                ->label(fn (): string => $this->record->is_available ? 'تعطيل المنتج إدارياً' : 'إعادة إتاحة المنتج إدارياً')
                ->color(fn (): string => $this->record->is_available ? 'danger' : 'success')
                ->visible(fn (): bool => SmProductResource::canModerate())
                ->form([
                    Textarea::make('reason')->label('سبب التدخل الإداري')->required()->maxLength(1000),
                ])
                ->requiresConfirmation()
                ->action(function (array $data): void {
                    /** @var SmProduct $record */
                    $record = $this->record;
                    $old = (bool) $record->is_available;
                    $record->forceFill(['is_available' => ! $old])->save();

                    activity('supermarket_admin_overrides')
                        ->causedBy(auth()->user())
                        ->performedOn($record)
                        ->withProperties(['field' => 'is_available', 'old' => $old, 'new' => ! $old, 'reason' => $data['reason']])
                        ->log('supermarket_product_availability_override');

                    Notification::make()->title('تم تحديث توفر المنتج')->success()->send();
                }),
        ];
    }
}
