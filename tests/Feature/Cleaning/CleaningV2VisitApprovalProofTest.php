<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\CleaningV2VisitApprovalProofService;
use Modules\Cleaning\Support\CleaningFinancialDefaults;

function approvalProofBooking(): array
{
    $date = now('Asia/Damascus')->addDays(4);
    $rooms = [
        ['roomKey' => 'proof.bedroom', 'roomType' => 'bedroom', 'size' => 'medium', 'cleaningMode' => 'regular'],
        ['roomKey' => 'proof.bathroom', 'roomType' => 'bathroom', 'size' => 'small', 'cleaningMode' => 'regular'],
    ];
    $visits = [];
    foreach ([1, 2] as $sequence) {
        $visits[] = [
            'visitKey' => 'proof.visit.'.$sequence,
            'sequence' => $sequence,
            'roomSchedules' => [
                ['scheduleKey' => 'proof.'.$sequence.'.bedroom', 'roomKey' => 'proof.bedroom', 'scheduledDate' => $date->copy()->addDays($sequence - 1)->toDateString(), 'scheduledTime' => '09:00', 'numberOfWorkers' => 2],
                ['scheduleKey' => 'proof.'.$sequence.'.bathroom', 'roomKey' => 'proof.bathroom', 'scheduledDate' => $date->copy()->addDays($sequence - 1)->toDateString(), 'scheduledTime' => '09:00', 'numberOfWorkers' => 2],
            ],
            'timePeriods' => [],
        ];
    }

    return [
        'contractVersion' => 2,
        'propertyType' => 'apartment',
        'bookingKind' => 'standard',
        'assignmentMode' => 'open_count',
        'genderPreference' => 'any',
        'propertyDetails' => ['address' => 'Approval proof fixture', 'room_size_breakdown' => ['bedroom' => ['medium' => 1], 'bathroom' => ['small' => 1]]],
        'addressLatitude' => 33.5138,
        'addressLongitude' => 36.2765,
        'numberOfWorkers' => 4,
        'rooms' => $rooms,
        'visits' => $visits,
    ];
}

function createApprovalProofBooking(bool $withPeriods = false): array
{
    $input = approvalProofBooking();
    $quote = test()->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)->assertOk()->json();
    if ($withPeriods) {
        foreach ($input['visits'] as $index => &$visit) {
            $visit['timePeriods'] = array_map(
                static fn (array $period): array => array_intersect_key($period, array_flip(['periodKey', 'roomScheduleKeys', 'materials', 'specialServices'])),
                $quote['visits'][$index]['timePeriods'],
            );
        }
        unset($visit);
        $quote = test()->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)->assertOk()->json();
    }
    $store = [...$input, 'quoteId' => $quote['quoteId'], 'quoteSignature' => $quote['quoteSignature'], 'idempotencyKey' => (string) Str::uuid(), 'termsAccepted' => true];
    $bookingId = test()->postJson('/api/v1/user/cleaning/orders', $store)->assertCreated()->json('order.id');
    $booking = CleaningBooking::query()->findOrFail($bookingId);
    $session = $booking->sessions()->where('sequence', 1)->firstOrFail();

    return [$booking, $session];
}

function approvalProofRelevantCounts(): array
{
    $tables = [
        'cleaning_booking_quotes', 'cleaning_bookings', 'cleaning_booking_sessions', 'cleaning_booking_rooms',
        'cleaning_room_schedules', 'cleaning_time_periods', 'cleaning_booking_session_tasks',
        'cleaning_booking_session_worker_assignments', 'cleaning_booking_session_supervisor_assignments',
        'cleaning_booking_session_supervisor_attendances', 'cleaning_booking_session_verifications',
        'cleaning_booking_session_supervisor_work_segments', 'cleaning_booking_session_payments',
        'cleaning_booking_session_deposits', 'cleaning_booking_material_kits', 'cleaning_booking_material_issues',
    ];

    $counts = [];
    foreach ($tables as $table) {
        if (Schema::hasTable($table)) {
            $counts[$table] = DB::table($table)->count();
        }
    }

    return $counts;
}

beforeEach(function (): void {
    $minutes = CleaningFinancialDefaults::roomTimeMinutes();
    $minutes['bedroom']['medium']['regular'] = 120;
    $minutes['bathroom']['small']['regular'] = 60;
    CleaningFinancialSetting::query()->updateOrCreate(['id' => 1], [
        'default_commission_rate' => 10,
        'commission_type' => 'percent',
        'cleaning_base_unit_price' => 50,
        'cleaning_minimum_order_price' => 0,
        'cleaning_room_pricing_units' => CleaningFinancialDefaults::roomPricingUnits(),
        'cleaning_room_deep_multipliers' => CleaningFinancialDefaults::roomDeepMultipliers(2),
        'cleaning_room_time_minutes' => $minutes,
    ]);
    foreach (['bedroom', 'bathroom'] as $roomType) {
        \Modules\Cleaning\Models\CleaningRoomType::query()->where('code', $roomType)->update([
            'suggested_minutes_by_size' => json_encode($minutes[$roomType], JSON_THROW_ON_ERROR),
        ]);
    }
    Sanctum::actingAs(User::factory()->create());
});

it('verifies accepted multi-visit signed proof stably across UTC and Damascus without writes after expiry', function (): void {
    [$booking, $session] = createApprovalProofBooking();
    $quoteExpiry = DB::table('cleaning_booking_quotes')->where('cleaning_booking_id', $booking->id)->value('expires_at');
    $this->travel(2)->hours();
    $before = approvalProofRelevantCounts();
    $service = app(CleaningV2VisitApprovalProofService::class);

    $originalTimezone = date_default_timezone_get();
    $originalAppTimezone = config('app.timezone');
    try {
        date_default_timezone_set('UTC');
        config(['app.timezone' => 'UTC']);
        $utc = $service->verify($booking->fresh(), $session->fresh());

        date_default_timezone_set('Asia/Damascus');
        config(['app.timezone' => 'Asia/Damascus']);
        $damascus = $service->verify(
            CleaningBooking::query()->findOrFail($booking->id),
            CleaningBookingSession::query()->findOrFail($session->id),
        );
    } finally {
        date_default_timezone_set($originalTimezone);
        config(['app.timezone' => $originalAppTimezone]);
    }

    expect($utc['valid'])->toBeTrue()
        ->and($utc['reasonCodes'])->toBe([])
        ->and($utc['evidenceDigest'])->toMatch('/^[a-f0-9]{64}$/')
        ->and($utc['taskCandidates'])->toHaveCount(2)
        ->and($utc['taskCandidates'])->toBe($damascus['taskCandidates'])
        ->and($utc['evidenceDigest'])->toBe($damascus['evidenceDigest'])
        ->and(array_column($utc['taskCandidates'], 'taskKey'))->toBe(['schedule:proof.1.bathroom', 'schedule:proof.1.bedroom'])
        ->and(array_column($utc['taskCandidates'], 'startsAt'))->each->toMatch('/Z$/')
        ->and($quoteExpiry)->toBeLessThan(now()->toDateTimeString())
        ->and(approvalProofRelevantCounts())->toBe($before);
});

it('fails closed without proof output when retained quote or required financial evidence is tampered', function (string $case): void {
    [$booking, $session] = createApprovalProofBooking();
    $quoteId = DB::table('cleaning_booking_quotes')->where('cleaning_booking_id', $booking->id)->value('id');
    if ($case === 'missing_signature') {
        DB::table('cleaning_booking_quotes')->where('id', $quoteId)->update(['signature' => str_repeat('0', 64)]);
    } elseif ($case === 'changed_request_hash') {
        DB::table('cleaning_booking_quotes')->where('id', $quoteId)->update(['request_hash' => str_repeat('a', 64)]);
    } elseif ($case === 'malformed_expiry') {
        DB::table('cleaning_booking_quotes')->where('id', $quoteId)->update(['expires_at' => '2026-02-31 25:61:00']);
    } elseif ($case === 'malformed_snapshot') {
        DB::table('cleaning_booking_quotes')->where('id', $quoteId)->update(['snapshot' => '{broken']);
    } elseif ($case === 'missing_included_admin_margin') {
        $snapshot = $session->pricing_snapshot;
        unset($snapshot['includedAdminMargin']);
        $session->forceFill(['pricing_snapshot' => $snapshot])->save();
    } elseif ($case === 'missing_cash_rounding_adjustment') {
        $snapshot = $session->pricing_snapshot;
        unset($snapshot['cashRoundingAdjustment']);
        $session->forceFill(['pricing_snapshot' => $snapshot])->save();
    } elseif ($case === 'tampered_source_duration') {
        DB::table('cleaning_room_schedules')->where('cleaning_booking_session_id', $session->id)->limit(1)->update(['duration_minutes' => 1]);
    }

    $result = app(CleaningV2VisitApprovalProofService::class)->verify($booking->fresh(), $session->fresh());
    expect($result['valid'])->toBeFalse()
        ->and($result['reasonCodes'])->not->toBe([])
        ->and($result['evidenceDigest'])->toBeNull()
        ->and($result['taskCandidates'])->toBe([])
        ->and(array_keys($result))->toBe(['valid', 'reasonCodes', 'evidenceDigest', 'taskCandidates']);
})->with([
    'invalid signature' => 'missing_signature',
    'changed request hash' => 'changed_request_hash',
    'invalid raw expiry' => 'malformed_expiry',
    'malformed signed snapshot' => 'malformed_snapshot',
    'missing included admin margin key' => 'missing_included_admin_margin',
    'missing cash rounding adjustment key' => 'missing_cash_rounding_adjustment',
    'tampered source duration' => 'tampered_source_duration',
]);

it('rejects duplicate quote links and mismatched visit binding', function (string $case): void {
    [$booking, $session] = createApprovalProofBooking();
    if ($case === 'duplicate_quote') {
        $original = DB::table('cleaning_booking_quotes')->where('cleaning_booking_id', $booking->id)->first();
        DB::table('cleaning_booking_quotes')->insert([
            'id' => (string) Str::uuid(), 'customer_id' => $original->customer_id,
            'request_hash' => $original->request_hash, 'signature' => $original->signature,
            'snapshot' => $original->snapshot, 'expires_at' => $original->expires_at,
            'idempotency_key' => (string) Str::uuid(), 'cleaning_booking_id' => $booking->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    } elseif ($case === 'wrong_visit_key') {
        $snapshot = $session->pricing_snapshot;
        $snapshot['visitKey'] = 'forged.visit';
        $session->forceFill(['pricing_snapshot' => $snapshot])->save();
    } elseif ($case === 'session_booking_mismatch') {
        $other = CleaningBooking::factory()->create(['booking_kind' => 'standard', 'number_of_workers' => 2, 'is_pricing_final' => true]);
        $result = app(CleaningV2VisitApprovalProofService::class)->verify($other, $session->fresh());
        expect($result['valid'])->toBeFalse()->and($result['evidenceDigest'])->toBeNull()->and($result['taskCandidates'])->toBe([]);

        return;
    }
    $result = app(CleaningV2VisitApprovalProofService::class)->verify($booking->fresh(), $session->fresh());
    expect($result['valid'])->toBeFalse()->and($result['evidenceDigest'])->toBeNull()->and($result['taskCandidates'])->toBe([]);
})->with([
    'duplicate linked quote' => 'duplicate_quote',
    'session visit key mismatch' => 'wrong_visit_key',
    'session belongs to another booking' => 'session_booking_mismatch',
]);

it('rejects mutation of a sibling visit source even when the selected visit is untouched', function (string $case): void {
    [$booking, $session] = createApprovalProofBooking();
    $sibling = $booking->sessions()->where('sequence', 2)->firstOrFail();
    $source = DB::table('cleaning_room_schedules')
        ->where('cleaning_booking_session_id', $sibling->id)->orderBy('id')->first();
    if ($case === 'altered_start') {
        DB::table('cleaning_room_schedules')->where('id', $source->id)->update(['starts_at' => '2030-01-01 01:00:00']);
    } elseif ($case === 'altered_worker_requirement') {
        DB::table('cleaning_room_schedules')->where('id', $source->id)->update(['required_workers' => 1]);
    } elseif ($case === 'altered_visit_key') {
        DB::table('cleaning_room_schedules')->where('id', $source->id)->update(['visit_key' => 'forged.visit']);
    } elseif ($case === 'deleted_source') {
        DB::table('cleaning_room_schedules')->where('id', $source->id)->delete();
    } elseif ($case === 'tampered_sibling_financial') {
        $sibling->forceFill(['total_price' => (float) $sibling->total_price + 1])->save();
    }

    $proof = app(CleaningV2VisitApprovalProofService::class)->verify($booking->fresh(), $session->fresh());
    expect($proof['valid'])->toBeFalse()
        ->and($proof['evidenceDigest'])->toBeNull()
        ->and($proof['taskCandidates'])->toBe([]);
})->with([
    'sibling start' => 'altered_start',
    'sibling workers' => 'altered_worker_requirement',
    'sibling visit link' => 'altered_visit_key',
    'missing sibling row' => 'deleted_source',
    'sibling financials' => 'tampered_sibling_financial',
]);

it('authenticates persisted time-period source links and rejects period tampering', function (string $case): void {
    [$booking, $session] = createApprovalProofBooking(true);
    $service = app(CleaningV2VisitApprovalProofService::class);
    $initial = $service->verify($booking->fresh(), $session->fresh());
    expect($initial['valid'])->toBeTrue();
    if ($case === 'untouched') {
        expect($initial['taskCandidates'])->toHaveCount(2);

        return;
    }
    $period = DB::table('cleaning_time_periods')->where('cleaning_booking_session_id', $session->id)->first();
    expect($period)->not->toBeNull();
    if ($case === 'period_start') {
        DB::table('cleaning_time_periods')->where('id', $period->id)->update(['starts_at' => '2030-01-01 00:00:00']);
    } elseif ($case === 'period_choice') {
        DB::table('cleaning_time_periods')->where('id', $period->id)->update(['materials_choice' => 'forged_choice']);
    } elseif ($case === 'missing_period') {
        DB::table('cleaning_time_periods')->where('id', $period->id)->delete();
    } elseif ($case === 'missing_schedule_link') {
        DB::table('cleaning_time_period_room_schedules')->where('cleaning_time_period_id', $period->id)->delete();
    }
    $result = $service->verify($booking->fresh(), $session->fresh());
    expect($result['valid'])->toBeFalse()->and($result['taskCandidates'])->toBe([])
        ->and($result['evidenceDigest'])->toBeNull();
})->with([
    'untouched' => 'untouched',
    'period date' => 'period_start',
    'material supply choice' => 'period_choice',
    'missing period' => 'missing_period',
    'missing schedule links' => 'missing_schedule_link',
]);

