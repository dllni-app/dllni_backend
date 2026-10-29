<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningRoomType;
use Modules\Cleaning\Services\CleaningV2VisitApprovalProofService;
use Modules\Cleaning\Support\CleaningFinancialDefaults;

function approvalProofBooking(bool $workerOwnedSibling = false): array
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
                ['scheduleKey' => 'proof.'.$sequence.'.bedroom', 'roomKey' => 'proof.bedroom', 'scheduledDate' => $date->copy()->addDays($sequence - 1)->toDateString(), 'scheduledTime' => '09:00', 'numberOfWorkers' => $workerOwnedSibling && $sequence === 2 ? 1 : 2],
                ['scheduleKey' => 'proof.'.$sequence.'.bathroom', 'roomKey' => 'proof.bathroom', 'scheduledDate' => $date->copy()->addDays($sequence - 1)->toDateString(), 'scheduledTime' => $workerOwnedSibling && $sequence === 2 ? '13:00' : '09:00', 'numberOfWorkers' => $workerOwnedSibling && $sequence === 2 ? 1 : 2],
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

function createApprovalProofBooking(bool $withPeriods = false, bool $workerOwnedSibling = false, bool $nonHourlyDuration = false): array
{
    if ($nonHourlyDuration) {
        $minutes = CleaningFinancialDefaults::roomTimeMinutes();
        $minutes['bedroom']['medium']['regular'] = 125;
        CleaningFinancialSetting::query()->whereKey(1)->update(['cleaning_room_time_minutes' => $minutes]);
        CleaningRoomType::query()->where('code', 'bedroom')->update([
            'suggested_minutes_by_size' => json_encode($minutes['bedroom'], JSON_THROW_ON_ERROR),
        ]);
    }
    $input = approvalProofBooking($workerOwnedSibling);
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

function approvalProofDatabaseRows(): array
{
    $counts = [];
    foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'") as $tableRow) {
        $table = (string) $tableRow->name;
        if (! Schema::hasTable($table)) {
            continue;
        }
        $rows = DB::table($table)->get()->map(static fn (object $row): array => (array) $row)->all();
        usort($rows, static fn (array $left, array $right): int => json_encode($left) <=> json_encode($right));
        $counts[$table] = $rows;
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
        'travel_markup_type' => 'worker_allowance',
        'travel_markup_value' => 0,
        'travel_per_km' => 0,
        'cleaning_room_pricing_units' => CleaningFinancialDefaults::roomPricingUnits(),
        'cleaning_room_deep_multipliers' => CleaningFinancialDefaults::roomDeepMultipliers(2),
        'cleaning_room_time_minutes' => $minutes,
    ]);
    foreach (['bedroom', 'bathroom'] as $roomType) {
        CleaningRoomType::query()->where('code', $roomType)->update([
            'suggested_minutes_by_size' => json_encode($minutes[$roomType], JSON_THROW_ON_ERROR),
        ]);
    }
    Sanctum::actingAs(User::factory()->create());
});

it('verifies accepted multi-visit signed proof stably across UTC and Damascus without writes after expiry', function (): void {
    [$booking, $session] = createApprovalProofBooking();
    $quoteExpiry = DB::table('cleaning_booking_quotes')->where('cleaning_booking_id', $booking->id)->value('expires_at');
    $this->travel(2)->hours();
    $before = approvalProofDatabaseRows();
    $service = app(CleaningV2VisitApprovalProofService::class);

    $originalTimezone = date_default_timezone_get();
    $originalAppTimezone = config('app.timezone');
    Event::fake();
    Mail::fake();
    Notification::fake();
    Queue::fake();
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
        ->and(approvalProofDatabaseRows())->toBe($before);
    $dispatchedEventNames = array_keys(Event::dispatchedEvents());
    expect(array_values(array_filter($dispatchedEventNames, static fn (string $event): bool => ! str_starts_with($event, 'eloquent.retrieved:'))))->toBe([]);
    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
});

it('rejects signed group visits with pending route travel fees using an explicit proof reason', function (): void {
    CleaningFinancialSetting::query()->whereKey(1)->update([
        'travel_markup_type' => 'fixed',
        'travel_per_km' => 10,
        'travel_markup_value' => 0,
    ]);
    [$booking, $session] = createApprovalProofBooking();

    expect($booking->property_details['_time_period_v2']['pricing']['travelFeePending'])->toBeTrue()
        ->and($booking->is_pricing_final)->toBeFalse()
        ->and($session->is_pricing_final)->toBeFalse();

    $result = app(CleaningV2VisitApprovalProofService::class)->verify($booking, $session);
    expect($result['valid'])->toBeFalse()
        ->and($result['reasonCodes'])->toBe(['travel_fee_pending'])
        ->and($result['evidenceDigest'])->toBeNull()
        ->and($result['taskCandidates'])->toBe([]);

    // A mismatched persisted finality flag does not make the signed pending fee final.
    $booking->forceFill(['is_pricing_final' => true])->save();
    $session->forceFill(['is_pricing_final' => true])->save();
    $result = app(CleaningV2VisitApprovalProofService::class)->verify($booking->fresh(), $session->fresh());
    expect($result['valid'])->toBeFalse()
        ->and($result['reasonCodes'])->toBe(['travel_fee_pending'])
        ->and($result['evidenceDigest'])->toBeNull();
});

it('fails closed without proof output when retained quote or required financial evidence is tampered', function (string $case): void {
    [$booking, $session] = createApprovalProofBooking();
    $originalAppKey = config('app.key');
    $initial = app(CleaningV2VisitApprovalProofService::class)->verify($booking->fresh(), $session->fresh());
    expect($initial['valid'])->toBeTrue();
    $quoteId = DB::table('cleaning_booking_quotes')->where('cleaning_booking_id', $booking->id)->value('id');
    if ($case === 'missing_signature') {
        DB::table('cleaning_booking_quotes')->where('id', $quoteId)->update(['signature' => str_repeat('0', 64)]);
    } elseif ($case === 'changed_request_hash') {
        DB::table('cleaning_booking_quotes')->where('id', $quoteId)->update(['request_hash' => str_repeat('a', 64)]);
    } elseif ($case === 'malformed_expiry') {
        DB::table('cleaning_booking_quotes')->where('id', $quoteId)->update(['expires_at' => '2026-02-31 25:61:00']);
    } elseif ($case === 'malformed_snapshot') {
        DB::table('cleaning_booking_quotes')->where('id', $quoteId)->update(['snapshot' => '{broken']);
    } elseif ($case === 'missing_quote') {
        DB::table('cleaning_booking_quotes')->where('id', $quoteId)->delete();
    } elseif ($case === 'wrong_quote_customer') {
        DB::table('cleaning_booking_quotes')->where('id', $quoteId)->update(['customer_id' => User::factory()->create()->id]);
    } elseif ($case === 'missing_key') {
        config(['app.key' => null]);
    } elseif ($case === 'rotated_key') {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('r', 32))]);
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
    config(['app.key' => $originalAppKey]);
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
    'missing quote linkage' => 'missing_quote',
    'wrong quote customer' => 'wrong_quote_customer',
    'missing signing key' => 'missing_key',
    'rotated signing key' => 'rotated_key',
    'missing included admin margin key' => 'missing_included_admin_margin',
    'missing cash rounding adjustment key' => 'missing_cash_rounding_adjustment',
    'tampered source duration' => 'tampered_source_duration',
]);

it('keeps a visit verifiable when another signed sibling is completed or has lower concurrency', function (string $case): void {
    [$booking, $session] = createApprovalProofBooking(workerOwnedSibling: $case === 'lower_concurrency');
    $sibling = $booking->sessions()->where('sequence', 2)->firstOrFail();
    $service = app(CleaningV2VisitApprovalProofService::class);
    expect($service->verify($booking->fresh(), $session->fresh())['valid'])->toBeTrue();

    if ($case === 'completed') {
        $sibling->forceFill(['status' => 'completed'])->save();
        $result = $service->verify($booking->fresh(), $session->fresh());
    } else {
        expect($sibling->supervision_mode)->toBe(CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR)
            ->and($service->verify($booking->fresh(), $session->fresh())['valid'])->toBeTrue();
    }

    if ($case === 'completed') {
        expect($result['valid'])->toBeTrue();
    }
})->with([
    'completed sibling' => 'completed',
    'lower concurrency sibling retains the requested team' => 'lower_concurrency',
]);

it('binds the signed requested team size to every visit even when a sibling has lower concurrency', function (): void {
    [$booking, $session] = createApprovalProofBooking(workerOwnedSibling: true);
    $sibling = $booking->sessions()->where('sequence', 2)->firstOrFail();
    $proof = app(CleaningV2VisitApprovalProofService::class);
    $signed = json_decode(DB::table('cleaning_booking_quotes')->where('cleaning_booking_id', $booking->id)->value('snapshot'), true, 512, JSON_THROW_ON_ERROR);

    expect($signed['requestedWorkerCount'])->toBe(4)
        ->and($signed['visits'][1]['requiredSimultaneousWorkers'])->toBe(1)
        ->and($sibling->requiredWorkerCount())->toBe(4)
        ->and($sibling->supervision_mode)->toBe(CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR)
        ->and($proof->verify($booking->fresh(), $session->fresh())['valid'])->toBeTrue();

    $sibling->forceFill(['required_workers' => 1, 'supervision_mode' => CleaningBookingSession::SUPERVISION_MODE_WORKER])->save();
    $invalid = $proof->verify($booking->fresh(), $session->fresh());
    expect($invalid['valid'])->toBeFalse()
        ->and($invalid['evidenceDigest'])->toBeNull()
        ->and($invalid['taskCandidates'])->toBe([]);
});

it('continues to reject a terminal selected visit', function (): void {
    [$booking, $session] = createApprovalProofBooking();
    $service = app(CleaningV2VisitApprovalProofService::class);
    expect($service->verify($booking->fresh(), $session->fresh())['valid'])->toBeTrue();

    $session->forceFill(['status' => 'completed'])->save();
    $result = $service->verify($booking->fresh(), $session->fresh());

    expect($result['valid'])->toBeFalse()
        ->and($result['evidenceDigest'])->toBeNull()
        ->and($result['taskCandidates'])->toBe([]);
});

it('rejects a sibling supervisor mode that conflicts with its signed worker requirement', function (): void {
    [$booking, $session] = createApprovalProofBooking();
    $sibling = $booking->sessions()->where('sequence', 2)->firstOrFail();
    $service = app(CleaningV2VisitApprovalProofService::class);
    expect($service->verify($booking->fresh(), $session->fresh())['valid'])->toBeTrue();

    $sibling->forceFill(['supervision_mode' => CleaningBookingSession::SUPERVISION_MODE_WORKER])->save();
    $result = $service->verify($booking->fresh(), $session->fresh());
    expect($result['valid'])->toBeFalse()
        ->and($result['evidenceDigest'])->toBeNull()
        ->and($result['taskCandidates'])->toBe([]);
});

it('binds signed non-hour-aligned duration to every persisted visit', function (string $case): void {
    [$booking, $session] = createApprovalProofBooking(nonHourlyDuration: true);
    $sibling = $booking->sessions()->where('sequence', 2)->firstOrFail();
    $service = app(CleaningV2VisitApprovalProofService::class);
    $signed = json_decode(DB::table('cleaning_booking_quotes')->where('cleaning_booking_id', $booking->id)->value('snapshot'), true, 512, JSON_THROW_ON_ERROR);
    $durationMinutes = $signed['visits'][0]['estimatedDurationMinutes'];
    $expectedHours = round($durationMinutes / 60, 2);
    $hasSignedZero = collect($signed['visits'])->contains(static fn (array $visit): bool => is_numeric($visit['financial']['cashRoundingAdjustment'] ?? null)
        && (float) $visit['financial']['cashRoundingAdjustment'] === 0.0);
    expect($service->verify($booking->fresh(), $session->fresh())['valid'])->toBeTrue()
        ->and($hasSignedZero)->toBeTrue()
        ->and((float) $session->pricing_snapshot['cashRoundingAdjustment'])->toBe(0.0)
        ->and($durationMinutes % 60)->not->toBe(0)
        ->and((float) $session->duration_hours)->toBe($expectedHours);

    $target = $case === 'selected' ? $session : $sibling;
    $target->forceFill(['duration_hours' => round($expectedHours + 0.01, 2)])->save();
    $result = $service->verify($booking->fresh(), $session->fresh());

    expect($result['valid'])->toBeFalse()
        ->and($result['evidenceDigest'])->toBeNull()
        ->and($result['taskCandidates'])->toBe([]);
})->with([
    'selected duration tamper' => 'selected',
    'sibling duration tamper' => 'sibling',
]);

it('binds each sibling financial snapshot margin and rounding evidence', function (string $case): void {
    [$booking, $session] = createApprovalProofBooking();
    $sibling = $booking->sessions()->where('sequence', 2)->firstOrFail();
    $service = app(CleaningV2VisitApprovalProofService::class);
    expect($service->verify($booking->fresh(), $session->fresh())['valid'])->toBeTrue();
    $snapshot = $sibling->pricing_snapshot;
    $key = str_contains($case, 'margin') ? 'includedAdminMargin' : 'cashRoundingAdjustment';
    if (str_starts_with($case, 'missing')) {
        unset($snapshot[$key]);
    } else {
        $snapshot[$key] = (float) $snapshot[$key] + 1;
    }
    $sibling->forceFill(['pricing_snapshot' => $snapshot])->save();

    $result = $service->verify($booking->fresh(), $session->fresh());
    expect($result['valid'])->toBeFalse()
        ->and($result['evidenceDigest'])->toBeNull()
        ->and($result['taskCandidates'])->toBe([]);
})->with([
    'missing sibling margin' => 'missing_margin',
    'altered sibling margin' => 'altered_margin',
    'missing sibling rounding' => 'missing_rounding',
    'altered sibling rounding' => 'altered_rounding',
]);

it('continues to verify the original approval after live pricing inputs change', function (): void {
    [$booking, $session] = createApprovalProofBooking();
    $service = app(CleaningV2VisitApprovalProofService::class);
    expect($service->verify($booking->fresh(), $session->fresh())['valid'])->toBeTrue();

    $setting = CleaningFinancialSetting::query()->findOrFail(1);
    $minutes = $setting->cleaning_room_time_minutes;
    $minutes['bedroom']['medium']['regular'] = 300;
    CleaningFinancialSetting::query()->whereKey(1)->update([
        'cleaning_base_unit_price' => 900,
        'cleaning_minimum_order_price' => 800,
        'cleaning_room_time_minutes' => $minutes,
    ]);
    CleaningRoomType::query()->where('code', 'bedroom')->update([
        'suggested_minutes_by_size' => json_encode($minutes['bedroom'], JSON_THROW_ON_ERROR),
    ]);

    $result = $service->verify($booking->fresh(), $session->fresh());
    expect($result['valid'])->toBeTrue()
        ->and($result['evidenceDigest'])->toMatch('/^[a-f0-9]{64}$/');
});

it('rejects unsupported approval families after a valid baseline proof', function (string $case): void {
    [$booking, $session] = createApprovalProofBooking();
    $service = app(CleaningV2VisitApprovalProofService::class);
    expect($service->verify($booking->fresh(), $session->fresh())['valid'])->toBeTrue();

    if ($case === 'legacy') {
        $booking->forceFill(['booking_kind' => 'supervised_team'])->save();
    } elseif ($case === 'open_time') {
        $session->forceFill(['session_type' => CleaningBookingSession::TYPE_OPEN_TIME])->save();
    } elseif ($case === 'room_period') {
        $room = DB::table('cleaning_booking_rooms')->where('cleaning_booking_id', $booking->id)->first();
        DB::table('cleaning_booking_room_periods')->insert([
            'cleaning_booking_id' => $booking->id,
            'cleaning_booking_session_id' => $session->id,
            'cleaning_booking_room_id' => $room->id,
            'client_key' => 'unsupported.room-period',
            'visit_sequence' => 1,
            'room_type' => $room->room_type,
            'room_size' => $room->room_size,
            'cleaning_mode' => $room->cleaning_mode,
            'scheduled_date' => now()->addDay()->toDateString(),
            'scheduled_time' => '09:00:00',
            'required_workers' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } elseif ($case === 'provisional') {
        $session->forceFill(['is_pricing_final' => false])->save();
    }

    $result = $service->verify($booking->fresh(), $session->fresh());
    expect($result['valid'])->toBeFalse()
        ->and($result['evidenceDigest'])->toBeNull()
        ->and($result['taskCandidates'])->toBe([]);
})->with([
    'legacy booking' => 'legacy',
    'open-time visit' => 'open_time',
    'room-period source' => 'room_period',
    'provisional visit' => 'provisional',
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
    $initial = app(CleaningV2VisitApprovalProofService::class)->verify($booking->fresh(), $session->fresh());
    expect($initial['valid'])->toBeTrue();
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
    } elseif ($case === 'malformed_start') {
        DB::table('cleaning_room_schedules')->where('id', $source->id)->update(['starts_at' => 'not-a-utc-time']);
    } elseif ($case === 'extra_source') {
        $room = DB::table('cleaning_booking_rooms')->where('id', $source->cleaning_booking_room_id)->first();
        $roomValues = (array) $room;
        unset($roomValues['id']);
        $roomValues['room_key'] .= '.extra';
        $roomValues['client_key'] = $roomValues['room_key'];
        $roomValues['created_at'] = now();
        $roomValues['updated_at'] = now();
        $extraRoomId = DB::table('cleaning_booking_rooms')->insertGetId($roomValues);
        $sourceValues = (array) $source;
        unset($sourceValues['id']);
        $sourceValues['cleaning_booking_room_id'] = $extraRoomId;
        $sourceValues['schedule_key'] .= '.extra';
        $sourceValues['created_at'] = now();
        $sourceValues['updated_at'] = now();
        DB::table('cleaning_room_schedules')->insert($sourceValues);
    } elseif ($case === 'cross_booking_source') {
        $other = CleaningBooking::factory()->create();
        DB::table('cleaning_room_schedules')->where('id', $source->id)->update(['cleaning_booking_id' => $other->id]);
    } elseif ($case === 'duplicate_visit_key') {
        DB::table('cleaning_room_schedules')->where('id', $source->id)->update(['visit_key' => DB::table('cleaning_room_schedules')->where('cleaning_booking_session_id', $session->id)->value('visit_key')]);
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
    'malformed UTC source' => 'malformed_start',
    'extra source' => 'extra_source',
    'cross-booking source' => 'cross_booking_source',
    'duplicate visit identity' => 'duplicate_visit_key',
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
