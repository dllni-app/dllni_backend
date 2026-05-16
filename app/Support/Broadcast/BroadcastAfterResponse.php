<?php

declare(strict_types=1);

namespace App\Support\Broadcast;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\Log;
use Throwable;

final class BroadcastAfterResponse
{
    /**
     * Defer broadcast delivery until after the HTTP response is sent, and swallow
     * Pusher/network errors so they never fail the primary request.
     */
    public static function send(ShouldBroadcast $event): void
    {
        try {
            app()->terminating(static function () use ($event): void {
                try {
                    broadcast($event);
                } catch (Throwable $e) {
                    Log::warning('Broadcast delivery failed after response.', [
                        'event' => $event::class,
                        'message' => $e->getMessage(),
                    ]);
                }
            });
        } catch (Throwable $e) {
            Log::warning('Broadcast after-response registration failed.', [
                'event' => $event::class,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
