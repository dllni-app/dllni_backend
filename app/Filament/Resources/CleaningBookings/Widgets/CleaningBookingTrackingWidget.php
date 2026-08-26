<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

final class CleaningBookingTrackingWidget extends Widget
{
    public ?Model $record = null;

    protected string $view = 'filament.resources.cleaning-bookings.widgets.cleaning-booking-tracking';

    protected int|string|array $columnSpan = 'full';

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $booking = $this->record instanceof CleaningBooking ? $this->record : null;

        if (! $booking instanceof CleaningBooking) {
            return ['trackingConfig' => null];
        }

        $status = $booking->status instanceof CleaningBookingStatus
            ? $booking->status
            : CleaningBookingStatus::tryFrom((string) $booking->status);
        $requiredWorkers = max(1, (int) ($booking->number_of_workers ?? 1));
        $acceptedWorkers = $booking->acceptedWorkerCount();

        return [
            'trackingConfig' => [
                'bookingId' => $booking->id,
                'bookingNumber' => (string) $booking->booking_number,
                'bookingStatus' => $status?->value ?? (string) $booking->status,
                'bookingStatusLabel' => $status?->label() ?? '-',
                'acceptedWorkers' => $acceptedWorkers,
                'requiredWorkers' => $requiredWorkers,
                'remainingWorkers' => max(0, $requiredWorkers - $acceptedWorkers),
                'destination' => $booking->address_latitude !== null && $booking->address_longitude !== null
                    ? [
                        'latitude' => (float) $booking->address_latitude,
                        'longitude' => (float) $booking->address_longitude,
                        'name' => filled($booking->neighborhood_name)
                            ? 'موقع العميل - '.$booking->neighborhood_name
                            : 'موقع العميل',
                    ]
                    : null,
                'trackingUrl' => route('admin.cleaning-bookings.tracking', ['cleaning_booking' => $booking->id]),
                'routingServiceUrl' => (string) config('services.osrm.url', 'https://router.project-osrm.org'),
                'pollIntervalMs' => 15000,
            ],
        ];
    }
}
