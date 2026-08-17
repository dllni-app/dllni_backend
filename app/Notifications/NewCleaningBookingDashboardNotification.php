<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Resources\CleaningBookings\CleaningBookingResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Modules\Cleaning\Models\CleaningBooking;

final class NewCleaningBookingDashboardNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly CleaningBooking $booking) {}

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
                ->title(__('cleaning_admin.booking_notification.created_title'))
                ->body(__('cleaning_admin.booking_notification.created_body', [
                    'booking' => $this->booking->booking_number ?? (string) $this->booking->id,
                ]))
                ->icon('heroicon-o-calendar-days')
                ->info()
                ->actions([
                    Action::make('view')
                        ->label(__('cleaning_admin.booking_notification.view'))
                        ->url(CleaningBookingResource::getUrl('view', ['record' => $this->booking]))
                        ->markAsRead(),
                ])
                ->getDatabaseMessage(),
            ['sound_type' => 'notify'],
        );
    }
}
