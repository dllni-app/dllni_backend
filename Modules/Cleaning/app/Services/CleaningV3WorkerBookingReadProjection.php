<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

/**
 * Fail-closed projection for a V3 worker's parent booking.
 *
 * The caller must have already verified the actual authenticated worker and
 * selected only authorized/eligible visit rows. This presenter deliberately
 * cannot expose customer identity, destination coordinates, private notes,
 * unrelated sibling assignments, or the booking-wide financial payload.
 *
 * Precise travel destinations are retrieved separately with an authorized
 * visit identity and never included in this coarse discovery response.
 */
final class CleaningV3WorkerBookingReadProjection
{
    /**
     * @param  array<string, mixed>  $booking
     * @param  list<array<string, mixed>>  $authorizedVisits
     * @return array<string, mixed>
     */
    public function present(array $booking, array $authorizedVisits): array
    {
        $visits = [];
        foreach ($authorizedVisits as $visit) {
            $id = (int) ($visit['sessionId'] ?? 0);
            if ($id < 1 || isset($visits[$id])) {
                continue;
            }

            $visits[$id] = [
                'sessionId' => $id,
                'scheduledDate' => $visit['scheduledDate'] ?? null,
                'scheduledTime' => $visit['scheduledTime'] ?? null,
                'status' => $visit['status'] ?? null,
            ];
        }

        $statuses = array_values(array_filter(array_column($visits, 'status'), 'is_string'));
        $status = $statuses === [] ? 'unavailable' : (new CleaningV3VisitStatusProjector)->project($statuses)['status'];

        return [
            'id' => (int) ($booking['id'] ?? 0),
            'bookingNumber' => (string) ($booking['bookingNumber'] ?? ''),
            'contractVersion' => 3,
            'status' => $status,
            'locationSummary' => (string) ($booking['locationSummary'] ?? 'المنطقة غير محددة'),
            'sessions' => array_values($visits),
        ];
    }
}
