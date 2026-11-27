<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;

/** Durable admission, not a client capability, JSON flag or receipt alone. */
final class CleaningV3OperationalAdmissionPolicy
{
    public function approved(CleaningBooking $booking, ?CleaningBookingSession $visit = null): bool
    {
        if ($booking->contract_version !== 'v3') {
            return true;
        }

        $quoteId = data_get($booking->property_details, 'v3_current_quote_id');
        if (data_get($booking->property_details, 'v3_admission_hold') !== false || ! is_string($quoteId)
            || ! Schema::hasTable('cleaning_v3_operational_approvals')) {
            return false;
        }

        $record = DB::table('cleaning_v3_operational_approvals')
            ->where('booking_id', $booking->id)->where('quote_id', $quoteId)->first();
        if ($record === null) {
            return false;
        }

        $catalogKey = CleaningV3CatalogVersion::query()->whereKey($booking->catalog_version_id)->value('version_key');
        if ($catalogKey === null || ($catalogKey === CleaningV3QaEnvironment::CATALOG_KEY
            && app(CleaningV3QaEnvironment::class)->signingKey() === null)) {
            return false;
        }

        $ids = json_decode((string) $record->visit_ids, true);
        if (! is_array($ids) || $ids === []) {
            return false;
        }

        return $visit === null || ((int) $visit->cleaning_booking_id === (int) $booking->id
            && in_array((int) $visit->id, $ids, true)
            && (int) $visit->price_revision >= (int) $record->price_revision);
    }
}
