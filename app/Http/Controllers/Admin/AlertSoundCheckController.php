<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Notifications\NewSupportCaseDashboardNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

final class AlertSoundCheckController
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['soundType' => null]);
        }

        $since = $request->string('since')->toString();

        $notifications = $user->notifications()
            ->when($since !== '', fn ($query) => $query->where('created_at', '>', $since))
            ->orderBy('created_at')
            ->get();

        $hardAlarm = $notifications->last(
            fn (DatabaseNotification $notification): bool => ($notification->data['sound_type'] ?? 'notify') === 'hard_alarm'
        );

        if ($hardAlarm instanceof DatabaseNotification) {
            return response()->json($this->payloadFor($hardAlarm, 'hard_alarm', true));
        }

        $latest = $notifications->last();

        if (! $latest instanceof DatabaseNotification) {
            return response()->json(['soundType' => null]);
        }

        return response()->json($this->payloadFor(
            $latest,
            (string) ($latest->data['sound_type'] ?? 'notify'),
            $latest->type === NewSupportCaseDashboardNotification::class,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(DatabaseNotification $notification, string $soundType, bool $showBanner): array
    {
        return [
            'soundType' => $soundType,
            'title' => (string) ($notification->data['title'] ?? ''),
            'body' => (string) ($notification->data['body'] ?? ''),
            'notificationId' => (string) $notification->id,
            'notificationType' => (string) $notification->type,
            'showBanner' => $showBanner,
        ];
    }
}
