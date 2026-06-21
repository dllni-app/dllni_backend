<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Resources\SosAlerts\SosAlertResource;
use App\Models\SosAlert;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

final class NewUserSosDashboardNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly SosAlert $sos)
    {
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Build a Filament-formatted database notification so it renders in the
     * admin notification bell with a clickable action that opens the SOS alert.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title(__('cleaning_admin.sos_notification.title'))
            ->body(__('cleaning_admin.sos_notification.body'))
            ->icon('heroicon-o-bell-alert')
            ->danger()
            ->actions([
                Action::make('view')
                    ->label(__('cleaning_admin.sos_notification.view'))
                    ->url(SosAlertResource::getUrl('view', ['record' => $this->sos]))
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
