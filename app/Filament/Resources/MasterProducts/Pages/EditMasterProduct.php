<?php

declare(strict_types=1);

namespace App\Filament\Resources\MasterProducts\Pages;

use App\Filament\Resources\MasterProducts\MasterProductResource;
use App\Filament\Resources\MasterProducts\Pages\Concerns\SyncsMasterProductImage;
use App\Models\MasterProduct;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

final class EditMasterProduct extends EditRecord
{
    use SyncsMasterProductImage;

    protected static string $resource = MasterProductResource::class;

    protected function afterSave(): void
    {
        $this->syncMasterProductImageFromForm();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('remove_image')
                ->label('حذف الصورة')
                ->icon('heroicon-o-photo')
                ->color('danger')
                ->visible(fn (): bool => $this->record->hasMedia(MasterProduct::IMAGE_COLLECTION))
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->clearMediaCollection(MasterProduct::IMAGE_COLLECTION);
                    Notification::make()->title('تم حذف صورة المنتج المرجعي')->success()->send();
                }),
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
