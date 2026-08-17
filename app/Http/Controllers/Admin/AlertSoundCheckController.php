<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $hardAlarm = $notifications->first(fn ($notification): bool => ($notification->data['sound_type'] ?? 'notify') === 'hard_alarm');

        if ($hardAlarm) {
            return response()->json([
                'soundType' => 'hard_alarm',
                'title' => (string) ($hardAlarm->data['title'] ?? ''),
                'body' => (string) ($hardAlarm->data['body'] ?? ''),
            ]);
        }

        return response()->json([
            'soundType' => $notifications->isNotEmpty() ? 'notify' : null,
        ]);
    }
}
