<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Resources\CleaningBookings\CleaningBookingResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

final class CleaningBookingStatusChangedDashboardNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly CleaningBooking $booking,
        private readonly string $fromStatus,
        private readonly string $toStatus,
    ) {}

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
                ->title(trans('cleaning_admin.booking_notification.status_changed_title', [], 'ar'))
                ->body(trans('cleaning_admin.booking_notification.status_changed_body', [
                    'booking' => $this->booking->booking_number ?? (string) $this->booking->id,
                    'from' => $this->arabicStatusLabel($this->fromStatus),
                    'to' => $this->arabicStatusLabel($this->toStatus),
                ], 'ar'))
                ->icon('heroicon-o-arrow-path')
                ->info()
                ->actions([
                    Action::make('view')
                        ->label(trans('cleaning_admin.booking_notification.view', [], 'ar'))
                        ->url(CleaningBookingResource::getUrl('view', ['record' => $this->booking]))
                        ->markAsRead(),
                ])
                ->getDatabaseMessage(),
            ['sound_type' => 'notify'],
        );
    }

    private function arabicStatusLabel(string $status): string
    {
        if ($status === CleaningBookingStatus::UnderDispute->value) {
            return 'قيد النزاع';
        }

        $key = 'cleaning_admin.enums.cleaning_booking_status.'.$status;
        $translated = trans($key, [], 'ar');

        return $translated === $key ? $status : $translated;
    }
}
