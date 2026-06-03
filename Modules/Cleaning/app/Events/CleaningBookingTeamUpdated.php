<?php

declare(strict_types=1);

namespace Modules\Cleaning\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

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
        return [
            new PrivateChannel('cleaning-booking.' . $this->cleaningBookingId),
        ];
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
            'team' => $this->team,
        ];
    }
}
