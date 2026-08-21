<?php

declare(strict_types=1);

namespace Modules\Cleaning\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Cleaning\Services\CleaningWorkerRealtimeAudienceService;

final class CleaningBookingTeamUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $team
     */
    public function __construct(
        public int $cleaningBookingId,
        public array $team,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('cleaning-booking.' . $this->cleaningBookingId),
        ];

        $workerIds = app(CleaningWorkerRealtimeAudienceService::class)
            ->workerIdsForBooking($this->cleaningBookingId);

        foreach ($workerIds as $workerId) {
            $channels[] = new PrivateChannel('cleaning-worker.' . $workerId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'cleaning_booking.team_updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'cleaningBookingId' => $this->cleaningBookingId,
            'bookingId' => $this->cleaningBookingId,
            'team' => $this->team,
        ];
    }
}
