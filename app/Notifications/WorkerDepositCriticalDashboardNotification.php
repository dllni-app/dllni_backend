<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Resources\CleaningWorkerDeposits\CleaningWorkerDepositsResource;
use App\Models\CleaningWorkerDeposit;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

final class WorkerDepositCriticalDashboardNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly CleaningWorkerDeposit $deposit) {}

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
                ->title(__('cleaning_admin.deposit_notification.title'))
                ->body(__('cleaning_admin.deposit_notification.body', [
                    'worker' => $this->deposit->worker?->user?->name ?? (string) $this->deposit->worker_id,
                    'debt' => number_format((float) $this->deposit->debt_balance, 2),
                ]))
                ->icon('heroicon-o-currency-dollar')
                ->danger()
                ->actions([
                    Action::make('view')
                        ->label(__('cleaning_admin.deposit_notification.view'))
                        ->url(CleaningWorkerDepositsResource::getUrl('index'))
                        ->markAsRead(),
                ])
                ->getDatabaseMessage(),
            ['sound_type' => 'hard_alarm'],
        );
    }
}
