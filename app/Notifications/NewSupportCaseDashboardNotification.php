<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\SupportCaseKind;
use App\Filament\Resources\SupportCases\SupportCaseResource;
use App\Models\SupportCase;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

final class NewSupportCaseDashboardNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly SupportCase $supportCase) {}

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
        $isEmergency = $this->supportCase->kind === SupportCaseKind::Emergency;
        $bookingReference = $this->supportCase->booking_id !== null
            ? (string) $this->supportCase->booking_id
            : 'غير محدد';

        $notification = FilamentNotification::make()
            ->title($isEmergency ? 'بلاغ طوارئ جديد (SOS)' : 'نزاع جديد')
            ->body($isEmergency
                ? "تم استلام بلاغ طوارئ جديد رقم {$this->supportCase->case_number} للحجز {$bookingReference} ويحتاج إلى متابعة فورية."
                : "تم فتح نزاع جديد رقم {$this->supportCase->case_number} للحجز {$bookingReference} ويحتاج إلى المراجعة.")
            ->icon($isEmergency ? 'heroicon-o-bell-alert' : 'heroicon-o-exclamation-triangle')
            ->actions([
                Action::make('view')
                    ->label('عرض البلاغ')
                    ->url(SupportCaseResource::getUrl('view', ['record' => $this->supportCase]))
                    ->markAsRead(),
            ]);

        if ($isEmergency) {
            $notification->danger();
        } else {
            $notification->warning();
        }

        return array_merge(
            $notification->getDatabaseMessage(),
            ['sound_type' => $isEmergency ? 'hard_alarm' : 'notify'],
        );
    }
}
