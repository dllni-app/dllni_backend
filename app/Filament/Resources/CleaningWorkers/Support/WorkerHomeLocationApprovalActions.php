<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkers\Support;

use App\Models\Worker;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

final class WorkerHomeLocationApprovalActions
{
    /** @return array<int, Action> */
    public static function make(): array
    {
        return [
            self::approve(),
            self::reject(),
        ];
    }

    public static function approve(): Action
    {
        return Action::make('approveHomeLocation')
            ->label('اعتماد موقع بدء المهمة')
            ->icon('heroicon-o-check')
            ->color('success')
            ->visible(fn (Worker $record): bool => $record->hasPendingHomeLocation())
            ->requiresConfirmation()
            ->modalHeading('اعتماد موقع بدء المهمة')
            ->modalDescription('سيتم استبدال الموقع المعتمد الحالي بالموقع المعلق.')
            ->modalSubmitActionLabel('اعتماد')
            ->action(function (Worker $record): void {
                $record->approvePendingHomeLocation();

                Notification::make()
                    ->title('تم اعتماد موقع بدء المهمة')
                    ->success()
                    ->send();
            });
    }

    public static function reject(): Action
    {
        return Action::make('rejectHomeLocation')
            ->label('رفض موقع بدء المهمة')
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->visible(fn (Worker $record): bool => $record->hasPendingHomeLocation())
            ->form([
                Textarea::make('rejection_reason')
                    ->label('سبب الرفض')
                    ->required()
                    ->rows(3),
            ])
            ->modalHeading('رفض موقع بدء المهمة')
            ->modalSubmitActionLabel('رفض')
            ->action(function (Worker $record, array $data): void {
                $record->rejectPendingHomeLocation((string) $data['rejection_reason']);

                Notification::make()
                    ->title('تم رفض موقع بدء المهمة')
                    ->success()
                    ->send();
            });
    }
}
