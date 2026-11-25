<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

/** Atomic transition from a confirmed unpaid order to visit-scoped discovery. */
final class CleaningV3OperationalAdmissionService
{
    public static function enabled(): bool
    {
        return (bool) config('cleaning_v3_catalog.operational_admission_enabled', false);
    }

    public static function admitted(CleaningBooking $booking): bool
    {
        return $booking->contract_version === 'v3'
            && data_get($booking->property_details, 'v3_admission_hold') === false
            && data_get($booking->property_details, 'v3_operational_admitted_at') !== null;
    }

    public function admit(int $bookingId): array
    {
        return DB::transaction(function () use ($bookingId): array {
            $booking = CleaningBooking::query()->whereKey($bookingId)->lockForUpdate()->firstOrFail();
            if (self::admitted($booking)) {
                return ['admitted' => true, 'replayed' => true, 'blockers' => []];
            }
            if (! self::enabled()) {
                return ['admitted' => false, 'replayed' => false, 'blockers' => ['V3_OPERATIONAL_ROLLOUT_DISABLED']];
            }
            $review = app(CleaningV3OperationalAdmissionReview::class)->inspect($bookingId);
            if (! $review['canDispatch']) {
                return ['admitted' => false, 'replayed' => false, 'blockers' => $review['blockers']];
            }
            $visits = $booking->sessions()->orderBy('id')->lockForUpdate()->get();
            foreach ($visits as $visit) {
                if ($visit->endsAt() === null) {
                    throw new DomainException('V3_VISIT_SCHEDULE_INVALID');
                }
                app(CleaningV3EquipmentResumeGuard::class)->extend($visit, $visit->endsAt()->utc());
            }
            $at = now('UTC');
            $booking->forceFill([
                'property_details' => [...$booking->property_details,
                    'v3_admission_hold' => false, 'v3_operational_admitted_at' => $at->toIso8601String()],
                'search_started_at' => $at,
            ])->saveQuietly();
            foreach ($visits as $visit) {
                $visit->setRelation('booking', $booking);
                app(CleaningV3VisitDispatchService::class)->openSearch($visit, $at);
            }

            return ['admitted' => true, 'replayed' => false, 'blockers' => []];
        }, 3);
    }

    /** Revalidate money and signed price at acceptance and before work, never from client data. */
    public function assertVisitPayable(CleaningBooking $booking, CleaningBookingSession $visit): void
    {
        if (! self::admitted($booking) || (int) $visit->cleaning_booking_id !== (int) $booking->id
            || ! DB::table('cleaning_v3_visit_dispatches')->where('cleaning_booking_session_id', $visit->id)->exists()) {
            throw new DomainException('V3_OPERATIONAL_ADMISSION_REQUIRED');
        }
        $request = app(CleaningV3OperationalFundingService::class)->requirement($booking, $visit);
        if (! $request['verified'] && ! ($request['cashPricePayable'] ?? false)) {
            throw new DomainException($request['blocker'] ?? 'V3_FINANCIAL_APPROVAL_REQUIRED');
        }
    }
}
