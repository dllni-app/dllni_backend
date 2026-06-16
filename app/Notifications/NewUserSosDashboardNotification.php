<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Resources\SosAlerts\SosAlertResource;
use App\Models\SosAlert;
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
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'user_sos',
            'title' => 'New SOS Alert',
            'body' => 'A user submitted an SOS request.',
            'sos_alert_id' => $this->sos->getKey(),
            'order_id' => $this->sos->order_id,
            'user_id' => $this->sos->user_id,
            'status' => $this->sos->status?->value ?? $this->sos->status,
            'message' => $this->sos->message,
            'url' => SosAlertResource::getUrl('view', ['record' => $this->sos]),
            'created_at' => now()->toISOString(),
        ];
    }
}
