<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers\Support;

use App\Models\Worker;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Modules\Cleaning\Services\DepositService;

final class WorkerSuspensionActions
{
    /** @return array<int, Action> */
    public static function make(): array
    {
        return [self::toggle()];
    }

    public static function toggle(): Action
    {
        return Action::make('toggleWorkerSuspension')
            ->label(fn (Worker $record): string => $record->is_suspended ? 'إلغاء إيقاف العامل' : 'إيقاف العامل')
            ->icon(fn (Worker $record): string => $record->is_suspended ? 'heroicon-o-check-circle' : 'heroicon-o-no-symbol')
            ->color(fn (Worker $record): string => $record->is_suspended ? 'success' : 'danger')
            ->requiresConfirmation()
            ->modalHeading(fn (Worker $record): string => $record->is_suspended ? 'إلغاء إيقاف العامل' : 'إيقاف العامل')
            ->modalDescription(fn (Worker $record): string => $record->is_suspended
                ? 'سيتمكن العامل من استقبال الطلبات الجديدة مجدداً إذا كانت بقية شروط الأهلية مستوفاة.'
                : 'لن يستقبل العامل أي طلبات جديدة، وسيظهر له في الصفحة الرئيسية أن الحساب أوقف من قبل الإدارة. الطلبات الحالية لن تُلغى تلقائياً.')
            ->modalSubmitActionLabel(fn (Worker $record): string => $record->is_suspended ? 'تأكيد إلغاء الإيقاف' : 'تأكيد الإيقاف')
            ->action(function (Worker $record): void {
                $shouldSuspend = ! (bool) $record->is_suspended;

                $attributes = [
                    'is_suspended' => $shouldSuspend,
                    'suspended_until' => null,
                ];

                if ($shouldSuspend) {
                    $attributes['security_deposit_status'] = 'suspended';
                }

                $record->forceFill($attributes)->save();

                if (! $shouldSuspend) {
                    $freshWorker = $record->fresh(['deposit']) ?? $record;
                    app(DepositService::class)->syncEligibilityStatus($freshWorker);
                }

                Notification::make()
                    ->title($shouldSuspend ? 'تم إيقاف العامل' : 'تم إلغاء إيقاف العامل')
                    ->body($shouldSuspend
                        ? 'لن يستقبل العامل طلبات جديدة حتى تلغي الإدارة الإيقاف.'
                        : 'يمكن للعامل استقبال الطلبات الجديدة عند استيفاء بقية شروط الأهلية.')
                    ->success()
                    ->send();
            });
    }
}
