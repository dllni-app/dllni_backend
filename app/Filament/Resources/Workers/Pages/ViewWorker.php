<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers\Pages;

use App\Filament\Resources\Workers\WorkerResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

final class ViewWorker extends ViewRecord
{
    protected static string $resource = WorkerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('suspend')
                ->label(fn () => $this->record->is_suspended ? 'إلغاء التعليق' : 'تعليق الحساب')
                ->color(fn () => $this->record->is_suspended ? 'success' : 'danger')
                ->requiresConfirmation()
                ->modalHeading(fn () => $this->record->is_suspended ? 'إلغاء تعليق الحساب' : 'تعليق حساب العامل')
                ->action(function (): void {
                    $this->record->update(['is_suspended' => ! $this->record->is_suspended]);
                    Notification::make()
                        ->title($this->record->is_suspended ? 'تم تعليق الحساب' : 'تم إلغاء التعليق')
                        ->success()
                        ->send();
                }),
            EditAction::make(),
        ];
    }
}
