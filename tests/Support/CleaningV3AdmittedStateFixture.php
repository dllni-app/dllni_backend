<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;
use Modules\Cleaning\Services\CleaningV3OperationalAdmissionPolicy;

/**
 * Isolated feature-test fixture for a previously admitted execution.
 * Never issue a commercial quote, payment, funding proof or real approval here.
 * Actual approval is separately exercised by the MySQL contract tests.
 */
final class CleaningV3AdmittedStateFixture
{
    public static function apply(CleaningBooking $booking, ?User $approver = null): void
    {
        if (! app()->environment('testing') || DB::connection()->getDriverName() !== 'sqlite'
            || DB::connection()->getDatabaseName() !== ':memory:' || DB::transactionLevel() < 1) {
            throw new LogicException('This fixture requires an isolated SQLite test transaction.');
        }

        $booking->refresh();
        $visits = $booking->sessions()->get();
        if ($booking->contract_version !== 'v3' || $visits->isEmpty()) {
            throw new LogicException('Expected persisted V3 execution fixtures.');
        }
        $quoteId = data_get($booking->property_details, 'v3_current_quote_id');
        $catalogId = $booking->catalog_version_id;
        if ($quoteId === null) {
            $catalog = CleaningV3CatalogVersion::query()->create(['title' => 'Isolated admission-state fixture']);
            $catalogId = $catalog->id;
            $quoteId = (string) Str::uuid();
            DB::table('cleaning_booking_quotes')->insert([
                'id' => $quoteId, 'customer_id' => $booking->customer_id,
                'request_hash' => str_repeat('0', 64), 'signature' => str_repeat('0', 64),
                'snapshot' => '{}', 'expires_at' => now()->addHour(),
                'cleaning_booking_id' => $booking->id, 'contract_version' => 'v3',
                'catalog_version_id' => $catalogId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        } elseif (! DB::table('cleaning_booking_quotes')->where('id', $quoteId)
            ->where('cleaning_booking_id', $booking->id)->where('customer_id', $booking->customer_id)->exists()) {
            throw new LogicException('Existing signed quote must belong to the fixture booking.');
        }
        $approver ??= User::factory()->create(['is_active' => true]);
        $approver->assignRole(\Spatie\Permission\Models\Role::findOrCreate('admin', 'web'));
        $booking->forceFill([
            'catalog_version_id' => $catalogId,
            'property_details' => [...($booking->property_details ?? []), 'v3_current_quote_id' => $quoteId, 'v3_admission_hold' => false],
        ])->saveQuietly();
        DB::table('cleaning_v3_operational_approvals')->insert([
            'booking_id' => $booking->id, 'quote_id' => $quoteId,
            'approved_by' => $approver->id, 'price_revision' => (int) $visits->min('price_revision'),
            'visit_ids' => json_encode($visits->modelKeys(), JSON_THROW_ON_ERROR), 'approved_at' => now(),
        ]);
        foreach ($visits as $visit) {
            if (! app(CleaningV3OperationalAdmissionPolicy::class)->approved($booking, $visit)) {
                throw new LogicException('Fixture does not satisfy the persisted admission policy.');
            }
        }
    }
}
