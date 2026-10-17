<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningMaterial;
use Modules\Cleaning\Models\CleaningMaterialType;
use Modules\Cleaning\Models\CleaningMaterialTypeQuantityRule;
use Modules\Cleaning\Models\CleaningMaterialUnit;
use Modules\Cleaning\Models\CleaningRoomType;
use Modules\Cleaning\Models\CleaningSpecialService;
use Modules\Cleaning\Support\CleaningFinancialDefaults;
use Modules\User\Models\UserAddress;

function timePeriodPayload(): array
{
    $date = now('Asia/Damascus')->addDays(3)->toDateString();

    return ['contractVersion' => 2, 'propertyType' => 'apartment', 'bookingKind' => 'standard', 'assignmentMode' => 'open_count', 'genderPreference' => 'any', 'propertyDetails' => ['address' => 'عنوان الحجز', 'room_size_breakdown' => ['bedroom' => ['medium' => 1], 'bathroom' => ['small' => 1], 'kitchen' => ['medium' => 1]]], 'addressLatitude' => 33.5138, 'addressLongitude' => 36.2765, 'rooms' => [['roomKey' => 'bedroom.1', 'roomType' => 'bedroom', 'size' => 'medium', 'cleaningMode' => 'deep'], ['roomKey' => 'bathroom.1', 'roomType' => 'bathroom', 'size' => 'small', 'cleaningMode' => 'regular'], ['roomKey' => 'kitchen.1', 'roomType' => 'kitchen', 'size' => 'medium', 'cleaningMode' => 'deep']], 'visits' => [['visitKey' => 'visit.1', 'sequence' => 1, 'roomSchedules' => [['scheduleKey' => 'schedule.1', 'roomKey' => 'bedroom.1', 'scheduledDate' => $date, 'scheduledTime' => '09:00', 'numberOfWorkers' => 2], ['scheduleKey' => 'schedule.2', 'roomKey' => 'bathroom.1', 'scheduledDate' => $date, 'scheduledTime' => '09:00', 'numberOfWorkers' => 1], ['scheduleKey' => 'schedule.3', 'roomKey' => 'kitchen.1', 'scheduledDate' => $date, 'scheduledTime' => '14:00', 'numberOfWorkers' => 2]], 'timePeriods' => []]]];
}

function timePeriodAcceptGroups(array $input, array $response): array
{
    foreach ($input['visits'] as $i => &$visit) {
        $visit['timePeriods'] = array_map(static fn (array $period): array => array_intersect_key($period, array_flip(['periodKey', 'roomScheduleKeys', 'materials', 'specialServices'])), $response['visits'][$i]['timePeriods']);
    }
    unset($visit);

    return $input;
}

function timePeriodStorePayload(array $input, array $quote): array
{
    return [...$input, 'quoteId' => $quote['quoteId'], 'quoteSignature' => $quote['quoteSignature'], 'idempotencyKey' => (string) Str::uuid(), 'termsAccepted' => true];
}

beforeEach(function (): void {
    $minutes = CleaningFinancialDefaults::roomTimeMinutes();
    $minutes['bedroom']['medium']['deep'] = 240;
    $minutes['bathroom']['small']['regular'] = 120;
    $minutes['kitchen']['medium']['deep'] = 240;
    CleaningFinancialSetting::query()->updateOrCreate(['id' => 1], ['default_commission_rate' => 10, 'commission_type' => 'percent', 'cleaning_base_unit_price' => 50, 'cleaning_minimum_order_price' => 0, 'cleaning_room_pricing_units' => CleaningFinancialDefaults::roomPricingUnits(), 'cleaning_room_deep_multipliers' => CleaningFinancialDefaults::roomDeepMultipliers(2), 'cleaning_room_time_minutes' => $minutes]);
    // Room durations are now managed through the room catalog rather than the
    // legacy financial multiplier matrix. Preserve the overlapping periods
    // that these scheduling tests intentionally exercise.
    foreach (['bedroom', 'bathroom', 'kitchen'] as $roomType) {
        CleaningRoomType::query()->where('code', $roomType)->update([
            'suggested_minutes_by_size' => json_encode($minutes[$roomType], JSON_THROW_ON_ERROR),
        ]);
    }
    Sanctum::actingAs(User::factory()->create());
});

it('groups exact matching intervals into one period with two independent room schedules', function (): void {
    $quote = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', timePeriodPayload())
        ->assertOk()->assertJsonCount(2, 'visits.0.timePeriods')
        ->assertJsonPath('visits.0.timePeriods.0.roomScheduleKeys', ['schedule.1', 'schedule.2'])
        ->assertJsonPath('visits.0.roomSchedules.0.endAt', now('Asia/Damascus')->addDays(3)->format('Y-m-d').'T11:00:00+03:00')
        ->assertJsonPath('requiredSimultaneousWorkers', 3)
        ->assertJsonMissingPath('visits.0.financial')->assertJsonMissingPath('basePrice')->assertJsonMissingPath('visits.0.roomSchedules.0.basePrice')
        ->json();
    expect($quote['quoteSignature'])->toHaveLength(64);
});

it('bills one supervisor across overlapping room schedules and snapshots approved group hours', function (): void {
    CleaningFinancialSetting::query()->where('id', 1)->update(['supervisor_hourly_rate' => 180]);
    $input = timePeriodPayload();
    $quote = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)
        ->assertOk()
        ->assertJsonPath('pricing.supervisor.hourlyRate', 180)
        ->assertJsonPath('pricing.supervisor.billableMinutes', 240)
        ->assertJsonPath('pricing.supervisor.fee', 720)->json();
    $bookingId = $this->postJson('/api/v1/user/cleaning/orders', timePeriodStorePayload($input, $quote))
        ->assertCreated()->json('order.id');
    $booking = CleaningBooking::query()->findOrFail($bookingId);

    expect($booking->property_details['_supervisor_pricing'])->toMatchArray([
        'hourlyRate' => 180, 'billableMinutes' => 240, 'fee' => 720,
    ]);
});

it('keeps partial overlaps separate and treats touching endpoints as non-overlapping', function (): void {
    $input = timePeriodPayload();
    $input['visits'][0]['roomSchedules'][1]['scheduledTime'] = '10:00';
    $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)
        ->assertOk()->assertJsonCount(3, 'visits.0.timePeriods')->assertJsonPath('requiredSimultaneousWorkers', 3);
    $input['visits'][0]['roomSchedules'][1]['scheduledTime'] = '11:00';
    $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)
        ->assertOk()->assertJsonCount(3, 'visits.0.timePeriods')->assertJsonPath('requiredSimultaneousWorkers', 2);
});

it('persists normalized rooms visits periods membership and retries the same approved booking once', function (): void {
    $input = timePeriodPayload();
    $quote = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)->assertOk()->json();
    $store = timePeriodStorePayload($input, $quote);
    $response = $this->postJson('/api/v1/user/cleaning/orders', $store)->assertCreated()->assertJsonPath('pricing.totalPrice', $quote['pricing']['totalPrice'])->assertJsonCount(2, 'visits.0.timePeriods');
    $id = $response->json('order.id');
    $this->postJson('/api/v1/user/cleaning/orders', $store)->assertCreated()->assertJsonPath('order.id', $id);
    $this->assertDatabaseCount('cleaning_bookings', 1);
    $this->assertDatabaseCount('cleaning_booking_sessions', 1);
    $this->assertDatabaseCount('cleaning_room_schedules', 3);
    $this->assertDatabaseCount('cleaning_time_periods', 2);
    $this->assertDatabaseCount('cleaning_time_period_room_schedules', 3);
    $this->assertDatabaseHas('cleaning_booking_rooms', ['client_key' => 'bedroom.1', 'cleaning_mode' => 'deep']);
    expect((float) CleaningBooking::query()->findOrFail($id)->total_price)->toBe((float) $quote['pricing']['totalPrice']);
    $changed = $store;
    $changed['visits'][0]['roomSchedules'][0]['scheduledTime'] = '08:00';
    $this->postJson('/api/v1/user/cleaning/orders', $changed)->assertUnprocessable()->assertJsonValidationErrors('idempotencyKey');
});

it('rejects expired tampered cross-user changed and replayed quotes without creating a booking', function (): void {
    $input = timePeriodPayload();
    $quote = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)->assertOk()->json();
    $store = timePeriodStorePayload($input, $quote);
    $store['quoteSignature'] = str_repeat('0', 64);
    $this->postJson('/api/v1/user/cleaning/orders', $store)->assertUnprocessable()->assertJsonValidationErrors('quoteSignature');
    $store['quoteSignature'] = $quote['quoteSignature'];
    DB::table('cleaning_booking_quotes')->where('id', $quote['quoteId'])->update(['expires_at' => now()->subMinute()]);
    $this->postJson('/api/v1/user/cleaning/orders', $store)->assertUnprocessable()->assertJsonValidationErrors('quoteId');
    $quote = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)->assertOk()->json();
    $store = timePeriodStorePayload($input, $quote);
    Sanctum::actingAs(User::factory()->create());
    $this->postJson('/api/v1/user/cleaning/orders', $store)->assertUnprocessable()->assertJsonValidationErrors('quoteId');
    $this->assertDatabaseCount('cleaning_bookings', 0);
});

it('invalidates approved pricing when the catalog changes', function (): void {
    $input = timePeriodPayload();
    $quote = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)->assertOk()->json();
    CleaningRoomType::query()->where('code', 'bedroom')->update(['deep_hourly_rate' => 100]);
    $this->postJson('/api/v1/user/cleaning/orders', timePeriodStorePayload($input, $quote))->assertUnprocessable()->assertJsonValidationErrors('quoteSignature');
    $this->assertDatabaseCount('cleaning_bookings', 0);
});

it('returns authoritative service duration and requires explicit split reconciliation', function (): void {
    $input = timePeriodPayload();
    $quote = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)->assertOk()->json();
    $input = timePeriodAcceptGroups($input, $quote);
    $service = CleaningSpecialService::query()->create(['name' => 'تنظيف تجريبي', 'pricing_unit' => 'piece', 'unit_code' => 'piece', 'input_type' => 'quantity', 'base_unit_price' => 25, 'estimated_duration_minutes' => 60, 'supports_dirtiness' => false, 'is_active' => true]);
    $input['visits'][0]['timePeriods'][0]['specialServices'] = [['serviceKey' => 'service.1', 'specialServiceId' => $service->id, 'targetRoomKeys' => ['bedroom.1'], 'quantity' => 1, 'dirtinessLevel' => null]];
    $response = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)->assertOk()->assertJsonPath('reconciliationRequired', true)->assertJsonPath('quoteId', null)->assertJsonPath('visits.0.roomSchedules.0.estimatedWorkMinutes', 300)->assertJsonCount(3, 'visits.0.timePeriods')->json();
    expect($response['visits'][0]['reconciliationSources'][0]['specialServices'][0]['targetRoomKeys'])->toBe(['bedroom.1']);
});

it('keeps the same service on two room targets as two operational items', function (): void {
    $input = timePeriodPayload();
    $quote = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)->assertOk()->json();
    $input = timePeriodAcceptGroups($input, $quote);
    $service = CleaningSpecialService::query()->create(['name' => 'خدمة مستقلة', 'pricing_unit' => 'piece', 'unit_code' => 'piece', 'input_type' => 'quantity', 'base_unit_price' => 25, 'estimated_duration_minutes' => 0, 'supports_dirtiness' => false, 'is_active' => true]);
    $input['visits'][0]['timePeriods'][0]['specialServices'] = [['serviceKey' => 'service.1', 'specialServiceId' => $service->id, 'targetRoomKeys' => ['bedroom.1', 'bathroom.1'], 'quantity' => 2, 'dirtinessLevel' => null]];
    $quote = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)->assertOk()->json();
    $this->postJson('/api/v1/user/cleaning/orders', timePeriodStorePayload($input, $quote))->assertCreated();
    $this->assertDatabaseCount('cleaning_time_period_services', 2);
    $this->assertDatabaseCount('cleaning_booking_special_services', 1);
    $this->assertDatabaseCount('cleaning_booking_special_service_items', 2);
    $this->assertDatabaseHas('cleaning_booking_special_services', ['quantity' => 4, 'total_price' => 100]);
});

it('deduplicates material packages in a multi-room period and atomically rolls back unavailable stock', function (): void {
    $unit = CleaningMaterialUnit::query()->create(['name' => 'عبوة', 'code' => 'bottle', 'is_active' => true]);
    $type = CleaningMaterialType::query()->create(['name' => 'مادة', 'cleaning_material_unit_id' => $unit->id, 'price_per_unit' => 10, 'is_active' => true]);
    CleaningMaterialTypeQuantityRule::query()->create(['cleaning_material_type_id' => $type->id, 'quantity_per_room' => 0.25, 'is_active' => true, 'requires_admin_resolution' => false]);
    $material = CleaningMaterial::query()->create(['name' => 'مخزون', 'cleaning_material_type_id' => $type->id, 'stock_quantity' => 0, 'is_active' => true]);
    $input = timePeriodPayload();
    $quote = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)->assertOk()->json();
    $input = timePeriodAcceptGroups($input, $quote);
    $input['visits'][0]['timePeriods'][0]['materials'] = ['choice' => 'customer_partial', 'materialTypeIds' => [$type->id]];
    $quote = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)->assertOk()->json();
    $this->postJson('/api/v1/user/cleaning/orders', timePeriodStorePayload($input, $quote))->assertUnprocessable()->assertJsonValidationErrors('visits');
    $this->assertDatabaseCount('cleaning_bookings', 0);
    $this->assertDatabaseCount('cleaning_room_schedules', 0);
    $this->assertDatabaseCount('cleaning_time_periods', 0);
    $material->update(['stock_quantity' => 10]);
    $response = $this->postJson('/api/v1/user/cleaning/orders', timePeriodStorePayload($input, $quote))->assertCreated();
    $this->assertDatabaseCount('cleaning_time_period_materials', 1);
    $this->assertDatabaseHas('cleaning_time_period_materials', ['quantity' => 1]);
    expect((float) $material->fresh()->stock_quantity)->toBe(9.0);
});

it('allows the same missing material across weekly visits and rejects duplicates inside one period', function (): void {
    $unit = CleaningMaterialUnit::query()->create(['name' => 'عبوة', 'code' => 'v2_weekly_bottle', 'is_active' => true]);
    $type = CleaningMaterialType::query()->create(['name' => 'مادة تكرار', 'cleaning_material_unit_id' => $unit->id, 'price_per_unit' => 10, 'is_active' => true]);
    CleaningMaterialTypeQuantityRule::query()->create(['cleaning_material_type_id' => $type->id, 'quantity_per_room' => 0.25, 'is_active' => true, 'requires_admin_resolution' => false]);
    CleaningMaterial::query()->create(['name' => 'مخزون التكرار', 'cleaning_material_type_id' => $type->id, 'stock_quantity' => 100, 'is_active' => true]);
    $input = timePeriodPayload();
    $second = $input['visits'][0];
    $second['visitKey'] = 'visit.2';
    $second['sequence'] = 2;
    foreach ($second['roomSchedules'] as &$schedule) {
        $schedule['scheduleKey'] .= '.week2';
        $schedule['scheduledDate'] = now('Asia/Damascus')->addDays(10)->toDateString();
    }
    unset($schedule);
    $input['visits'][] = $second;
    $input['recurrence'] = ['cadence' => 'weekly'];
    $initial = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)->assertOk()->json();
    $input = timePeriodAcceptGroups($input, $initial);
    foreach ($input['visits'] as &$visit) {
        $visit['timePeriods'][0]['materials'] = ['choice' => 'customer_partial', 'materialTypeIds' => [$type->id]];
    }
    unset($visit);
    $quote = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)
        ->assertOk()
        ->assertJsonMissingValidationErrors()
        ->json();
    $this->postJson('/api/v1/user/cleaning/orders', timePeriodStorePayload($input, $quote))
        ->assertCreated()->assertJsonCount(2, 'visits');
    $this->assertDatabaseCount('cleaning_booking_sessions', 2);
    $this->assertDatabaseCount('cleaning_booking_materials', 1);
    $this->assertDatabaseHas('cleaning_booking_materials', ['cleaning_material_type_id' => $type->id, 'quantity' => 1]);
    $this->assertDatabaseHas('cleaning_materials', ['cleaning_material_type_id' => $type->id, 'stock_quantity' => 99]);

    $input['visits'][0]['timePeriods'][0]['materials']['materialTypeIds'] = [$type->id, $type->id];
    $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('visits.0.timePeriods.0.materials.materialTypeIds');
});

it('preserves separately dated recurring visits with one stable booking-wide room set', function (): void {
    $input = timePeriodPayload();
    $second = $input['visits'][0];
    $second['visitKey'] = 'visit.2';
    $second['sequence'] = 2;
    foreach ($second['roomSchedules'] as &$schedule) {
        $schedule['scheduledDate'] = now('Asia/Damascus')->addDays(10)->toDateString();
    }
    unset($schedule);
    $input['visits'][] = $second;
    $quote = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)->assertOk()->assertJsonCount(2, 'visits')->json();
    $this->postJson('/api/v1/user/cleaning/orders', timePeriodStorePayload($input, $quote))->assertCreated();
    $this->assertDatabaseCount('cleaning_booking_rooms', 3);
    $this->assertDatabaseCount('cleaning_booking_sessions', 2);
    $this->assertDatabaseCount('cleaning_room_schedules', 6);
    $this->assertDatabaseCount('cleaning_time_periods', 4);
});

it('rejects invalid room ownership quantities workers schedules and unsupported mixes', function (string $kind): void {
    $input = timePeriodPayload();
    match ($kind) {
        'worker' => $input['visits'][0]['roomSchedules'][0]['numberOfWorkers'] = 0,
        'missing' => array_pop($input['visits'][0]['roomSchedules']),
        'duplicate' => $input['visits'][0]['roomSchedules'][1]['roomKey'] = 'bedroom.1',
        'past' => $input['visits'][0]['roomSchedules'][0]['scheduledDate'] = '2020-01-01',
        'overnight' => $input['visits'][0]['roomSchedules'][0]['scheduledTime'] = '23:00',
        'mixed' => $input['requestMaterials'] = true,
    };
    $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)->assertUnprocessable();
    $this->assertDatabaseCount('cleaning_bookings', 0);
})->with(['worker', 'missing', 'duplicate', 'past', 'overnight', 'mixed']);

it('requires authentication for the corrected estimate and create contract', function (): void {
    auth()->forgetGuards();
    $this->postJson('/api/v1/user/cleaning/orders/estimate-price', timePeriodPayload())->assertUnauthorized();
    $this->postJson('/api/v1/user/cleaning/orders', timePeriodPayload())->assertUnauthorized();
});

it('supports a preferred worker for independently scheduled rooms and blocks insufficient or inactive teams', function (): void {
    $hours = array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], ['available' => true, 'data' => [['from' => '00:00', 'to' => '23:59']]]);
    $workers = Worker::factory()->financiallyEligible()->count(3)->create(['default_working_hours' => $hours, 'trust_score' => 100, 'home_address' => 'عنوان العامل', 'home_latitude' => 33.51, 'home_longitude' => 36.27]);
    $input = timePeriodPayload();
    $input['workerScope'] = 'specific';
    $input['preferredWorkerIds'] = $workers->pluck('id')->all();
    $quote = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)->assertOk()->json();
    $this->postJson('/api/v1/user/cleaning/orders', timePeriodStorePayload($input, $quote))->assertCreated();
    $this->assertDatabaseHas('cleaning_bookings', ['worker_scope' => 'specific', 'number_of_workers' => 3]);
    $input['preferredWorkerIds'] = [$workers[0]->id];
    // One preferred worker can join a larger open-dispatch team.
    $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)
        ->assertOk()->assertJsonPath('requiredSimultaneousWorkers', 3);
    $input['preferredWorkerIds'] = $workers->pluck('id')->all();
    $workers[0]->update(['is_active' => false]);
    $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)->assertUnprocessable()->assertJsonValidationErrors('preferredWorkerIds');
});

it('accepts a customer team larger than the room count but caps the team at six', function (): void {
    $input = timePeriodPayload();
    $input['numberOfWorkers'] = 4;
    $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)->assertOk();
    $input['numberOfWorkers'] = 7;
    $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)
        ->assertUnprocessable()->assertJsonValidationErrors('numberOfWorkers');
    $input['numberOfWorkers'] = 2;
    $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)
        ->assertUnprocessable()->assertJsonValidationErrors('numberOfWorkers');
});

it('preserves single preferred-worker selection and checks the entire service interval', function (): void {
    $hours = array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], ['available' => true, 'data' => [['from' => '08:00', 'to' => '13:00']]]);
    $worker = Worker::factory()->financiallyEligible()->create(['default_working_hours' => $hours, 'trust_score' => 100, 'home_address' => 'عنوان العامل', 'home_latitude' => 33.51, 'home_longitude' => 36.27]);
    $input = timePeriodPayload();
    $input['propertyDetails']['room_size_breakdown'] = ['bathroom' => ['small' => 1]];
    $input['rooms'] = [$input['rooms'][1]];
    $input['visits'][0]['roomSchedules'] = [$input['visits'][0]['roomSchedules'][1]];
    $input['assignmentMode'] = 'preferred_worker';
    $input['preferredWorkerId'] = $worker->id;
    $quote = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)->assertOk()->json();
    $this->postJson('/api/v1/user/cleaning/orders', timePeriodStorePayload($input, $quote))->assertCreated();
    $this->assertDatabaseHas('cleaning_bookings', ['assignment_mode' => 'preferred_worker', 'preferred_worker_id' => $worker->id]);
    $input['visits'][0]['roomSchedules'][0]['scheduledTime'] = '12:00';
    $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)->assertUnprocessable()->assertJsonValidationErrors('preferredWorkerIds');
});

it('uses the same saved-address normalization for approved quote and creation', function (): void {
    $address = UserAddress::query()->create(['user_id' => auth()->id(), 'label' => 'المنزل', 'city' => 'دمشق', 'street' => 'شارع تجريبي', 'latitude' => 33.5138, 'longitude' => 36.2765]);
    $input = timePeriodPayload();
    $input['addressId'] = $address->id;
    unset($input['propertyDetails']['address']);
    $quote = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)->assertOk()->json();
    $this->postJson('/api/v1/user/cleaning/orders', timePeriodStorePayload($input, $quote))->assertCreated();
    expect(CleaningBooking::query()->first()->property_details['address'])->toContain('دمشق');
});

it('rejects a consumed quote with a new key while allowing the original retry after expiry', function (): void {
    $input = timePeriodPayload();
    $quote = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)->assertOk()->json();
    $store = timePeriodStorePayload($input, $quote);
    $id = $this->postJson('/api/v1/user/cleaning/orders', $store)->assertCreated()->json('order.id');
    DB::table('cleaning_booking_quotes')->where('id', $quote['quoteId'])->update(['expires_at' => now()->subHour()]);
    $this->postJson('/api/v1/user/cleaning/orders', $store)->assertCreated()->assertJsonPath('order.id', $id);
    $store['idempotencyKey'] = (string) Str::uuid();
    $this->postJson('/api/v1/user/cleaning/orders', $store)->assertUnprocessable()->assertJsonValidationErrors('quoteId');
    $this->assertDatabaseCount('cleaning_bookings', 1);
});

it('keeps visit service prices on their real visit and visit totals equal to the approved total', function (): void {
    $input = timePeriodPayload();
    $second = $input['visits'][0];
    $second['visitKey'] = 'visit.2';
    $second['sequence'] = 2;
    foreach ($second['roomSchedules'] as &$schedule) {
        $schedule['scheduledDate'] = now('Asia/Damascus')->addDays(10)->toDateString();
    }
    unset($schedule);
    $input['visits'][] = $second;
    $quote = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)->assertOk()->json();
    $input = timePeriodAcceptGroups($input, $quote);
    $service = CleaningSpecialService::query()->create(['name' => 'خدمة الزيارة الثانية', 'pricing_unit' => 'piece', 'unit_code' => 'piece', 'input_type' => 'quantity', 'base_unit_price' => 125, 'estimated_duration_minutes' => 0, 'supports_dirtiness' => false, 'is_active' => true]);
    $input['visits'][1]['timePeriods'][0]['specialServices'][] = ['serviceKey' => 'service.1', 'specialServiceId' => $service->id, 'targetRoomKeys' => ['bedroom.1'], 'quantity' => 1, 'dirtinessLevel' => null];
    $quote = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)->assertOk()->json();
    $id = $this->postJson('/api/v1/user/cleaning/orders', timePeriodStorePayload($input, $quote))->assertCreated()->json('order.id');
    $booking = CleaningBooking::query()->findOrFail($id);
    $sessions = $booking->sessions()->get();
    expect((float) $sessions[0]->special_services_total)->toBe(0.0)
        ->and((float) $sessions[1]->special_services_total)->toBe(125.0)
        ->and(round((float) $sessions->sum('total_price'), 2))->toBe((float) $booking->total_price)
        ->and((float) $sessions[0]->duration_hours)->toBe(7.0);
    $this->assertDatabaseHas('cleaning_booking_special_service_sessions', ['cleaning_booking_session_id' => $sessions[1]->id]);
});

it('does not accept ignored coupon codes for period bookings', function (): void {
    $input = timePeriodPayload();
    $input['couponCode'] = 'UNSUPPORTED';
    $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)->assertUnprocessable()->assertJsonValidationErrors('couponCode');
});

it('enforces concurrency and visit window policies on the server', function (string $kind): void {
    $input = timePeriodPayload();
    if ($kind === 'capacity') {
        $input['visits'][0]['roomSchedules'][0]['numberOfWorkers'] = 20;
        $input['visits'][0]['roomSchedules'][1]['numberOfWorkers'] = 20;
    } else {
        $second = $input['visits'][0];
        $second['visitKey'] = 'visit.2';
        $second['sequence'] = 2;
        if ($kind === 'window') {
            foreach ($second['roomSchedules'] as &$schedule) {
                $schedule['scheduledDate'] = now('Asia/Damascus')->addDays(100)->toDateString();
            }
            unset($schedule);
        }
        $input['visits'][] = $second;
    }
    $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)->assertUnprocessable();
})->with(['capacity', 'window', 'duplicate_date']);

it('previews authoritative materials before supplier selection without creating a quote', function (): void {
    $unit = CleaningMaterialUnit::query()->create(['name' => 'لتر', 'code' => 'liter', 'is_active' => true]);
    $type = CleaningMaterialType::query()->create([
        'name' => 'منظف', 'cleaning_material_unit_id' => $unit->id, 'price_per_unit' => 25, 'is_active' => true,
    ]);
    foreach ([['bedroom', 'medium', 'deep', 1.5], ['bathroom', 'small', 'regular', 0.25]] as [$room, $size, $mode, $quantity]) {
        CleaningMaterialTypeQuantityRule::query()->create([
            'cleaning_material_type_id' => $type->id, 'room_type' => $room, 'room_size' => $size,
            'cleaning_mode' => $mode, 'quantity_per_room' => $quantity,
            'is_active' => true, 'requires_admin_resolution' => false,
        ]);
    }
    CleaningMaterial::query()->create([
        'name' => 'مخزون', 'cleaning_material_type_id' => $type->id,
        'stock_quantity' => 0, 'is_active' => true,
    ]);
    $rooms = array_slice(timePeriodPayload()['rooms'], 0, 2);
    $url = '/api/v1/user/cleaning/orders/materials-preview';
    $response = $this->postJson($url, ['contractVersion' => 2, 'rooms' => $rooms])
        ->assertOk()->assertJsonPath('materialsPreview.catalogStatus', 'ready')
        ->assertJsonCount(1, 'materialsPreview.items')
        ->assertJsonPath('materialsPreview.items.0.materialTypeId', $type->id)
        ->assertJsonPath('materialsPreview.items.0.quantity', 1.5)
        ->assertJsonPath('materialsPreview.items.0.requiredForRoomKeys', ['bathroom.1', 'bedroom.1'])
        ->assertJsonMissingPath('materialsPreview.items.0.unitPrice')->json();
    expect($response['materialsPreview']['roomWorkMinutes']['bedroom.1'])->toBeGreaterThan(0);
    expect($response['materialsPreview']['roomWorkMinutes']['bathroom.1'])->toBeGreaterThan(0);
    $this->postJson($url, ['contractVersion' => 2, 'rooms' => $rooms])
        ->assertOk()->assertJsonPath('materialsPreview.sourceRevision', $response['materialsPreview']['sourceRevision']);
    $rooms[0]['cleaningMode'] = 'regular';
    $this->postJson($url, ['contractVersion' => 2, 'rooms' => $rooms])
        ->assertOk()->assertJsonPath('materialsPreview.items.0.quantity', 0.25);
    $rooms[0]['roomKey'] = $rooms[1]['roomKey'];
    $this->postJson($url, ['contractVersion' => 2, 'rooms' => $rooms])
        ->assertUnprocessable()->assertJsonValidationErrors('rooms.1.roomKey');
    $this->assertDatabaseCount('cleaning_booking_quotes', 0);
});

it('rehydrates approved V2 material choices and targeted services from database rows after create', function (): void {
    $unit = CleaningMaterialUnit::query()->create(['name' => 'لتر', 'code' => 'liter', 'is_active' => true]);
    $type = CleaningMaterialType::query()->create([
        'name' => 'منظف', 'cleaning_material_unit_id' => $unit->id, 'price_per_unit' => 10, 'is_active' => true,
    ]);
    CleaningMaterialTypeQuantityRule::query()->create([
        'cleaning_material_type_id' => $type->id, 'quantity_per_room' => 0.75,
        'is_active' => true, 'requires_admin_resolution' => false,
    ]);
    CleaningMaterial::query()->create([
        'name' => 'مخزون', 'cleaning_material_type_id' => $type->id,
        'stock_quantity' => 10, 'is_active' => true,
    ]);
    $special = CleaningSpecialService::query()->create([
        'name' => 'تنظيف إضافي', 'pricing_unit' => 'piece', 'unit_code' => 'piece',
        'input_type' => 'quantity', 'base_unit_price' => 25,
        'estimated_duration_minutes' => 0, 'supports_dirtiness' => false, 'is_active' => true,
    ]);
    $input = timePeriodPayload();
    $initial = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)->assertOk()->json();
    $input = timePeriodAcceptGroups($input, $initial);
    $input['visits'][0]['timePeriods'][0]['materials'] = [
        'choice' => 'customer_partial', 'materialTypeIds' => [$type->id],
    ];
    $input['visits'][0]['timePeriods'][0]['specialServices'] = [[
        'serviceKey' => 'service.extra', 'specialServiceId' => $special->id,
        'targetRoomKeys' => ['bedroom.1', 'bathroom.1'],
        'quantity' => 2, 'dirtinessLevel' => null,
    ]];
    $quote = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)
        ->assertOk()->assertJsonPath('reconciliationRequired', false)->json();
    $created = $this->postJson('/api/v1/user/cleaning/orders', timePeriodStorePayload($input, $quote))
        ->assertCreated()->assertJsonPath('visits.0.timePeriods.0.materials.choice', 'customer_partial')
        ->assertJsonPath('visits.0.timePeriods.0.materials.materialTypeIds', [$type->id])
        ->assertJsonPath('visits.0.timePeriods.0.specialServices.0.targetRoomKeys', ['bathroom.1', 'bedroom.1'])
        ->assertJsonPath('pricing.coverage', 'all_visits')->json();
    $booking = CleaningBooking::query()->findOrFail($created['order']['id']);
    $data = $booking->property_details;
    $data['_time_period_v2']['visits'] = [];
    $data['_time_period_v2']['rooms'] = [];
    $booking->forceFill(['property_details' => $data])->saveQuietly();

    $this->getJson('/api/v1/user/cleaning/orders/'.$booking->id)
        ->assertOk()->assertJsonPath('data.contractVersion', 2)
        ->assertJsonPath('data.rooms.0.roomKey', 'bedroom.1')
        ->assertJsonPath('data.rooms.0.cleaningMode', 'deep')
        ->assertJsonPath('data.visits.0.timePeriods.0.materials.materialTypeIds', [$type->id])
        ->assertJsonPath('data.visits.0.timePeriods.0.platformMaterials.0.materialTypeId', $type->id)
        ->assertJsonPath('data.visits.0.timePeriods.0.specialServices.0.specialServiceId', $special->id)
        ->assertJsonPath('data.visits.0.timePeriods.0.specialServices.0.targetRoomKeys', ['bathroom.1', 'bedroom.1'])
        ->assertJsonPath('data.recurrence.visitCount', 1)
        ->assertJsonPath('data.pricing.totalPrice', $quote['pricing']['totalPrice'])
        ->assertJsonMissingPath('data.basePrice')
        ->assertJsonMissingPath('data.adminMargin')
        ->assertJsonMissingPath('data.travelFee')
        ->assertJsonMissingPath('data.bookingAdminMargin')
        ->assertJsonMissingPath('data.bookingBasePrice')
        ->assertJsonMissingPath('data.bookingTravelFee')
        ->assertJsonMissingPath('data.workerAmount')
        ->assertJsonMissingPath('data.serviceShareAmount');
    $this->assertDatabaseCount('cleaning_time_period_services', 2);
    $this->assertDatabaseCount('cleaning_time_period_materials', 1);
});

it('validates explicit weekly cadence and rejects monthly dates missing the requested day', function (): void {
    $this->travelTo(Carbon\CarbonImmutable::parse('2026-01-10 08:00:00', 'Asia/Damascus'));
    $input = timePeriodPayload();
    foreach ($input['visits'][0]['roomSchedules'] as &$schedule) {
        $schedule['scheduledDate'] = '2026-01-31';
    }
    unset($schedule);
    $second = $input['visits'][0];
    $second['visitKey'] = 'visit.2';
    $second['sequence'] = 2;
    foreach ($second['roomSchedules'] as &$schedule) {
        $schedule['scheduledDate'] = '2026-02-28';
    }
    unset($schedule);
    $input['visits'][] = $second;
    $input['recurrence'] = ['cadence' => 'monthly'];
    $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)
        ->assertUnprocessable()->assertJsonValidationErrors('visits');
    $this->assertDatabaseCount('cleaning_booking_quotes', 0);

    $input['recurrence'] = ['cadence' => 'weekly'];
    foreach ($input['visits'][1]['roomSchedules'] as &$schedule) {
        $schedule['scheduledDate'] = '2026-02-07';
    }
    unset($schedule);
    $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)
        ->assertOk()->assertJsonCount(2, 'visits');
});

it('rejects inconsistent booking-wide team counts without limiting team size to room count', function (): void {
    $input = timePeriodPayload();
    $input['numberOfWorkers'] = 2;
    $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)
        ->assertUnprocessable()->assertJsonValidationErrors('numberOfWorkers');
    $input['numberOfWorkers'] = 3;
    $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)
        ->assertOk()->assertJsonPath('requiredSimultaneousWorkers', 3);
});

it('accepts more workers than rooms within server capacity and persists the requested team', function (): void {
    $input = timePeriodPayload();
    $input['rooms'] = [$input['rooms'][0]];
    $input['propertyDetails']['room_size_breakdown'] = ['bedroom' => ['medium' => 1]];
    $input['visits'][0]['roomSchedules'] = [$input['visits'][0]['roomSchedules'][0]];
    $input['visits'][0]['roomSchedules'][0]['numberOfWorkers'] = 4;
    $input['numberOfWorkers'] = 4;

    $quote = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)
        ->assertOk()->assertJsonPath('requiredSimultaneousWorkers', 4)->json();
    $stored = $this->postJson('/api/v1/user/cleaning/orders', timePeriodStorePayload($input, $quote))
        ->assertCreated()->assertJsonPath('workforce.requestedWorkers', 4)
        ->assertJsonPath('visits.0.roomSchedules.0.numberOfWorkers', 4)
        ->json();
    $this->getJson('/api/v1/user/cleaning/orders/'.$stored['order']['id'])
        ->assertOk()->assertJsonPath('data.workforce.requestedWorkers', 4);
});

it('requires customer authentication for the read-only V2 materials preview', function (): void {
    auth()->forgetGuards();
    $this->postJson('/api/v1/user/cleaning/orders/materials-preview', [
        'contractVersion' => 2, 'rooms' => array_slice(timePeriodPayload()['rooms'], 0, 1),
    ])->assertUnauthorized();
});

it('returns persisted weekly visit dates and a total covering all visits after reload', function (): void {
    $input = timePeriodPayload();
    $second = $input['visits'][0];
    $second['visitKey'] = 'visit.2';
    $second['sequence'] = 2;
    foreach ($second['roomSchedules'] as &$schedule) {
        $schedule['scheduledDate'] = now('Asia/Damascus')->addDays(10)->toDateString();
    }
    unset($schedule);
    $input['visits'][] = $second;
    $input['recurrence'] = ['cadence' => 'weekly'];
    $quote = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)
        ->assertOk()->assertJsonPath('pricing.coverage', 'all_visits')->json();
    $created = $this->postJson('/api/v1/user/cleaning/orders', timePeriodStorePayload($input, $quote))
        ->assertCreated()->assertJsonPath('recurrence.visitCount', 2)->json();
    $this->getJson('/api/v1/user/cleaning/orders/'.$created['order']['id'])
        ->assertOk()->assertJsonPath('data.recurrence.cadence', 'weekly')
        ->assertJsonPath('data.recurrence.visitCount', 2)
        ->assertJsonPath('data.pricing.coverage', 'all_visits')
        ->assertJsonPath('data.visits.1.roomSchedules.0.scheduledDate', now('Asia/Damascus')->addDays(10)->toDateString());
});

it('scopes persisted V2 detail reads to the authenticated customer', function (): void {
    $input = timePeriodPayload();
    $quote = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)
        ->assertOk()->json();
    $created = $this->postJson('/api/v1/user/cleaning/orders', timePeriodStorePayload($input, $quote))
        ->assertCreated()->json();
    $id = $created['order']['id'];
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/user/cleaning/orders/'.$id)->assertNotFound();
});

it('retains all-materials supply decisions and an empty specialist selection on create and GET', function (string $choice): void {
    $unit = CleaningMaterialUnit::query()->create(['name' => 'لتر', 'code' => 'liter', 'is_active' => true]);
    $type = CleaningMaterialType::query()->create([
        'name' => 'معقم', 'cleaning_material_unit_id' => $unit->id, 'price_per_unit' => 10, 'is_active' => true,
    ]);
    CleaningMaterialTypeQuantityRule::query()->create([
        'cleaning_material_type_id' => $type->id, 'quantity_per_room' => 0.25,
        'is_active' => true, 'requires_admin_resolution' => false,
    ]);
    CleaningMaterial::query()->create([
        'name' => 'عبوة', 'cleaning_material_type_id' => $type->id,
        'stock_quantity' => 10, 'is_active' => true,
    ]);
    $input = timePeriodPayload();
    $first = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)->assertOk()->json();
    $input = timePeriodAcceptGroups($input, $first);
    $input['visits'][0]['timePeriods'][0]['materials'] = ['choice' => $choice, 'materialTypeIds' => []];
    $input['visits'][0]['timePeriods'][0]['specialServices'] = [];
    $quote = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)->assertOk()->json();
    $result = $this->postJson('/api/v1/user/cleaning/orders', timePeriodStorePayload($input, $quote))
        ->assertCreated()->assertJsonPath('visits.0.timePeriods.0.materials.choice', $choice)->json();
    $this->getJson('/api/v1/user/cleaning/orders/'.$result['order']['id'])
        ->assertOk()->assertJsonPath('data.visits.0.timePeriods.0.materials.choice', $choice)
        ->assertJsonCount(0, 'data.visits.0.timePeriods.0.specialServices')
        ->assertJsonCount($choice === 'platform_all' ? 1 : 0, 'data.visits.0.timePeriods.0.platformMaterials');
})->with(['platform_all', 'customer_all']);
