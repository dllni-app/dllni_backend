<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingRoomPeriod;
use Modules\User\Http\Requests\Concerns\RoomPeriodContract;

function roomPeriodPhaseOnePayload(): array
{
    $date = now(config('app.timezone'))->addDays(3)->toDateString();
    return [
        'propertyType' => 'apartment',
        'bookingKind' => 'standard',
        'propertyDetails' => [
            'address' => 'Damascus, QA booking street',
            'room_size_breakdown' => [
                'bedroom' => ['small' => 1, 'medium' => 1, 'large' => 0],
            ],
        ],
        'scheduledDate' => $date,
        'scheduledTime' => '09:00',
        'addressLatitude' => 33.5138,
        'addressLongitude' => 36.2765,
        'numberOfWorkers' => 2,
        'assignmentMode' => 'open_count',
        'termsAccepted' => true,
        'roomPeriods' => [
            [
                'clientKey' => 'bedroom.1',
                'visitSequence' => 1,
                'roomType' => 'bedroom',
                'size' => 'small',
                'cleaningMode' => 'deep',
                'scheduledDate' => $date,
                'scheduledTime' => '09:00',
                'numberOfWorkers' => 2,
                'materials' => ['providedByPlatform' => true, 'materialTypeIds' => []],
                'specialServices' => [],
                'notes' => 'QA deep',
            ],
            [
                'clientKey' => 'bedroom.2',
                'visitSequence' => 1,
                'roomType' => 'bedroom',
                'size' => 'medium',
                'cleaningMode' => 'regular',
                'scheduledDate' => $date,
                'scheduledTime' => '11:00',
                'numberOfWorkers' => 1,
                'materials' => ['providedByPlatform' => false, 'materialTypeIds' => []],
                'specialServices' => [],
            ],
        ],
    ];
}

function roomPeriodPhaseOneValidator(array $data): \Illuminate\Validation\Validator
{
    $validator = Validator::make($data, RoomPeriodContract::rules());
    $validator->after(static function ($validator) use ($data): void {
        RoomPeriodContract::validateConsistency($data, $validator);
    });
    return $validator;
}

it('creates an isolated room-period schema without changing legacy booking tables', function (): void {
    expect(Schema::hasTable('cleaning_booking_room_periods'))->toBeTrue();
    expect(Schema::hasColumns('cleaning_booking_room_periods', [
        'cleaning_booking_id', 'cleaning_booking_session_id', 'cleaning_booking_room_id',
        'visit_sequence', 'client_key', 'scheduled_date', 'scheduled_time',
        'required_workers', 'cleaning_mode', 'materials', 'special_services',
        'estimated_duration_minutes', 'pricing_snapshot',
    ]))->toBeTrue();
    expect(Schema::hasTable('cleaning_booking_rooms'))->toBeTrue();
});

it('validates the complete room-period contract and retains independent choices', function (): void {
    $input = roomPeriodPhaseOnePayload();
    $validator = roomPeriodPhaseOneValidator($input);
    expect($validator->passes())->toBeTrue()
        ->and($input['roomPeriods'][0]['numberOfWorkers'])->toBe(2)
        ->and($input['roomPeriods'][1]['numberOfWorkers'])->toBe(1)
        ->and($input['roomPeriods'][0]['materials']['providedByPlatform'])->toBeTrue()
        ->and($input['roomPeriods'][1]['materials']['providedByPlatform'])->toBeFalse();
});

it('rejects duplicate periods, mismatched room types, and skipped configured rooms', function (): void {
    $input = roomPeriodPhaseOnePayload();
    $input['roomPeriods'][1]['clientKey'] = 'bedroom.1';
    $validator = roomPeriodPhaseOneValidator($input);
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('roomPeriods.1.clientKey'))->toBeTrue()
        ->and($validator->errors()->has('roomPeriods'))->toBeTrue();

    $input = roomPeriodPhaseOnePayload();
    $input['roomPeriods'][1]['roomType'] = 'kitchen';
    expect(roomPeriodPhaseOneValidator($input)->errors()->has('roomPeriods.1.roomType'))->toBeTrue();

    $input = roomPeriodPhaseOnePayload();
    array_pop($input['roomPeriods']);
    expect(roomPeriodPhaseOneValidator($input)->errors()->has('roomPeriods'))->toBeTrue();
});

it('rejects invalid per-period workers and date before any pricing or persistence', function (): void {
    $input = roomPeriodPhaseOnePayload();
    $input['roomPeriods'][0]['numberOfWorkers'] = 0;
    $input['roomPeriods'][1]['scheduledDate'] = '2020-01-01';
    $validator = roomPeriodPhaseOneValidator($input);
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('roomPeriods.0.numberOfWorkers'))->toBeTrue()
        ->and($validator->errors()->has('roomPeriods.1.scheduledDate'))->toBeTrue();
});

it('rejects mixing period-based extras with legacy global materials', function (): void {
    $input = roomPeriodPhaseOnePayload();
    $input['requestMaterials'] = true;
    $validator = roomPeriodPhaseOneValidator($input);
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('requestMaterials'))->toBeTrue();
});

it('returns a period estimate and requires a signed quote for store', function (): void {
    Sanctum::actingAs(User::factory()->create());
    $input = roomPeriodPhaseOnePayload();
    $before = CleaningBooking::query()->count();

    $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)
        ->assertOk()->assertJsonPath('requiredSimultaneousWorkers', 2)
        ->assertJsonCount(2, 'roomPeriods');
    $this->postJson('/api/v1/user/cleaning/orders', $input)
        ->assertUnprocessable()->assertJsonValidationErrors('quoteSignature');

    expect(CleaningBooking::query()->count())->toBe($before);
});

it('does not inject periods into legacy bookings that omit the new field', function (): void {
    Sanctum::actingAs(User::factory()->create());
    $input = roomPeriodPhaseOnePayload();
    unset($input['roomPeriods']);
    // Old payload should pass the new contract's own rules, regardless of
    // other pre-existing booking and address validation.
    expect(roomPeriodPhaseOneValidator($input)->passes())->toBeTrue()
        ->and(class_exists(CleaningBookingRoomPeriod::class))->toBeTrue();
});


it('persists separately configured room periods under their booking without overwriting one another', function (): void {
    $booking = CleaningBooking::factory()->create();
    $day = now(config('app.timezone'))->addDays(4)->toDateString();
    $first = $booking->roomPeriods()->create([
        'client_key' => 'bedroom.1',
        'visit_sequence' => 1,
        'room_type' => 'bedroom',
        'room_size' => 'medium',
        'cleaning_mode' => 'deep',
        'scheduled_date' => $day,
        'scheduled_time' => '09:00',
        'required_workers' => 2,
        'estimated_work_minutes' => 120,
        'estimated_duration_minutes' => 60,
        'materials' => ['providedByPlatform' => true, 'materialTypeIds' => []],
        'special_services' => [],
    ]);
    $second = $booking->roomPeriods()->create([
        'client_key' => 'bedroom.2',
        'visit_sequence' => 1,
        'room_type' => 'bedroom',
        'room_size' => 'small',
        'cleaning_mode' => 'regular',
        'scheduled_date' => $day,
        'scheduled_time' => '11:00',
        'required_workers' => 1,
        'estimated_work_minutes' => 30,
        'estimated_duration_minutes' => 30,
        'materials' => ['providedByPlatform' => false, 'materialTypeIds' => []],
        'special_services' => [],
    ]);
    $stored = $booking->roomPeriods()->get()->keyBy('client_key');
    expect($stored)->toHaveCount(2)
        ->and($stored['bedroom.1']->required_workers)->toBe(2)
        ->and($stored['bedroom.2']->required_workers)->toBe(1)
        ->and($stored['bedroom.1']->materials['providedByPlatform'])->toBeTrue()
        ->and($stored['bedroom.2']->materials['providedByPlatform'])->toBeFalse()
        ->and($first->booking->id)->toBe($booking->id)
        ->and($second->booking->id)->toBe($booking->id);

    // A second visit can have the same stable room key without colliding.
    $booking->roomPeriods()->create([
        'client_key' => 'bedroom.1',
        'visit_sequence' => 2,
        'room_type' => 'bedroom',
        'room_size' => 'medium',
        'cleaning_mode' => 'regular',
        'scheduled_date' => now(config('app.timezone'))->addDays(5)->toDateString(),
        'scheduled_time' => '10:00',
        'required_workers' => 1,
        'materials' => ['providedByPlatform' => false, 'materialTypeIds' => []],
        'special_services' => [],
    ]);
    expect($booking->roomPeriods()->count())->toBe(3);
});


it('reversibly migrates the room period table on the isolated sqlite test database', function (): void {
    $migration = require base_path('Modules/Cleaning/database/migrations/2026_10_14_230000_create_cleaning_booking_room_periods_table.php');
    expect(Schema::hasTable('cleaning_booking_room_periods'))->toBeTrue();
    $migration->down();
    expect(Schema::hasTable('cleaning_booking_room_periods'))->toBeFalse();
    $migration->up();
    expect(Schema::hasTable('cleaning_booking_room_periods'))->toBeTrue();
});


it('computes independent room-period durations and one aggregate transport charge', function (): void {
    $payload = roomPeriodPhaseOnePayload();
    $quote = app(\Modules\User\Services\RoomPeriodPricingService::class)->quote($payload);
    expect($quote['roomPeriods'])->toHaveCount(2)
        ->and($quote['roomPeriods'][0]['estimatedWorkMinutes'])->toBeGreaterThan(0)
        ->and($quote['roomPeriods'][0]['estimatedDurationMinutes'])->toBe((int) ceil($quote['roomPeriods'][0]['estimatedWorkMinutes'] / 2))
        ->and($quote['roomPeriods'][1]['estimatedDurationMinutes'])->toBe($quote['roomPeriods'][1]['estimatedWorkMinutes'])
        ->and($quote['requiredSimultaneousWorkers'])->toBe(2)
        ->and($quote['basePrice'])->toBeGreaterThan(0)
        ->and($quote['totalPrice'])->toBeGreaterThanOrEqual($quote['basePrice']);

    // Concurrent rooms should require three worker slots, not two.
    $payload['roomPeriods'][1]['scheduledTime'] = '09:00';
    $parallel = app(\Modules\User\Services\RoomPeriodPricingService::class)->quote($payload);
    expect($parallel['requiredSimultaneousWorkers'])->toBe(3)
        ->and($parallel['basePrice'])->toBe($quote['basePrice'])
        ->and($parallel['roomPeriods'][0]['estimatedDurationMinutes'])->toBe($quote['roomPeriods'][0]['estimatedDurationMinutes']);
});


it('creates the period booking from a signed estimate with independent worker and materials settings', function (): void {
    $customer = User::factory()->create();
    Sanctum::actingAs($customer);
    \App\Models\CancellationPolicy::query()->create([
        'module' => 'cleaning', 'name' => 'Room QA policy', 'description' => 'QA',
        'rules' => ['free_until_hours' => 24], 'is_active' => true, 'is_default' => true,
    ]);
    \Modules\Cleaning\Models\CleaningBillingPolicy::query()->create([
        'name' => 'Room period QA', 'billing_mode' => \Modules\Cleaning\Enums\CleaningBillingMode::FullBookedTime->value,
        'rules' => [], 'is_active' => true, 'is_default' => true,
    ]);

    $input = roomPeriodPhaseOnePayload();
    $input['propertyDetails']['bedrooms'] = 2;
    $input['propertyDetails']['rooms'] = 2;
    $input['propertyDetails']['cleaning_mode'] = 'regular';

    $estimate = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)->assertOk();
    $signature = $estimate->json('quoteSignature');
    expect($signature)->toBeString()->toHaveLength(64);

    // Tampering after quote must not insert an order.
    $tampered = $input;
    $tampered['roomPeriods'][0]['cleaningMode'] = 'regular';
    $tampered['quoteSignature'] = $signature;
    $this->postJson('/api/v1/user/cleaning/orders', $tampered)
        ->assertUnprocessable()->assertJsonValidationErrors('quoteSignature');

    $input['quoteSignature'] = $signature;
    $response = $this->postJson('/api/v1/user/cleaning/orders', $input)->assertCreated()
        ->assertJsonCount(2, 'roomPeriods');
    $booking = CleaningBooking::query()->findOrFail((int) $response->json('order.id'));
    $periods = $booking->roomPeriods()->get()->keyBy('client_key');

    expect($periods)->toHaveCount(2)
        ->and($periods['bedroom.1']->required_workers)->toBe(2)
        ->and($periods['bedroom.2']->required_workers)->toBe(1)
        ->and($periods['bedroom.1']->materials['providedByPlatform'])->toBeTrue()
        ->and($periods['bedroom.2']->materials['providedByPlatform'])->toBeFalse()
        ->and($periods['bedroom.1']->cleaning_mode)->toBe('deep')
        ->and($periods['bedroom.2']->cleaning_mode)->toBe('regular')
        ->and((float) $booking->total_price)->toBe((float) $estimate->json('pricing.totalPrice'))
        ->and($booking->number_of_workers)->toBe(2)
        ->and((float) $booking->property_details['_room_period_v2_approved_total'])
        ->toBe((float) $estimate->json('pricing.totalPrice'));

    // Worker transport/administration amounts may differ after assignment,
    // but the customer-approved V2 total must not silently drift.
    $booking->forceFill([
        'travel_fee' => (float) $booking->travel_fee + 1000,
        'admin_margin_amount' => (float) $booking->admin_margin_amount + 1000,
    ])->save();
    expect((float) $booking->fresh()->total_price)
        ->toBe((float) $estimate->json('pricing.totalPrice'));
});


it('adds specialized service quantity time to its own room period only', function (): void {
    $service = \Modules\Cleaning\Models\CleaningSpecialService::query()->create([
        'name' => 'QA special duration',
        'pricing_unit' => 'piece',
        'base_unit_price' => 40,
        'estimated_duration_minutes' => 25,
        'supports_dirtiness' => false,
        'is_active' => true,
    ]);
    $data = roomPeriodPhaseOnePayload();
    $calculator = app(\Modules\User\Services\RoomPeriodPricingService::class);
    $ordinary = $calculator->quote($data);
    $data['roomPeriods'][0]['specialServices'] = [[
        'specialServiceId' => (int) $service->id,
        'quantity' => 2,
    ]];
    $new = $calculator->quote($data);
    expect($new['roomPeriods'][0]['estimatedWorkMinutes'])
        ->toBe($ordinary['roomPeriods'][0]['estimatedWorkMinutes'] + 50)
        ->and($new['roomPeriods'][0]['estimatedDurationMinutes'])
        ->toBe((int) ceil($new['roomPeriods'][0]['estimatedWorkMinutes'] / 2))
        ->and($new['roomPeriods'][1]['estimatedWorkMinutes'])
        ->toBe($ordinary['roomPeriods'][1]['estimatedWorkMinutes'])
        ->and($new['specialServicesTotal'])->toBe(80.0);
});


it('rejects recurring schedule envelopes when periods only cover the first visit', function (): void {
    $input = roomPeriodPhaseOnePayload();
    $input['schedule'] = [
        'mode' => 'recurring',
        'sessions' => [['date' => $input['roomPeriods'][0]['scheduledDate'], 'time' => '09:00']],
    ];
    expect(roomPeriodPhaseOneValidator($input)->errors()->has('schedule'))->toBeTrue();
});

it('rejects period durations over eight hours without sufficient workers', function (): void {
    $service = \Modules\Cleaning\Models\CleaningSpecialService::query()->create([
        'name' => 'QA extended service 10 hours', 'pricing_unit' => 'piece',
        'base_unit_price' => 10, 'estimated_duration_minutes' => 600,
        'supports_dirtiness' => false, 'is_active' => true,
    ]);
    $input = roomPeriodPhaseOnePayload();
    $input['roomPeriods'][0]['numberOfWorkers'] = 1;
    $input['roomPeriods'][0]['specialServices'] = [[
        'specialServiceId' => (int) $service->id, 'quantity' => 1,
    ]];
    expect(fn () => app(\Modules\User\Services\RoomPeriodPricingService::class)->quote($input))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});


it('reserves one shared material kit and materializes specialized services from the signed period booking', function (): void {
    $customer = User::factory()->create();
    Sanctum::actingAs($customer);
    \App\Models\CancellationPolicy::query()->create([
        'module' => 'cleaning', 'name' => 'QA shared period supplies',
        'description' => 'QA', 'rules' => ['free_until_hours' => 24],
        'is_active' => true, 'is_default' => true,
    ]);

    $now = now();
    $unitId = \Illuminate\Support\Facades\DB::table('cleaning_material_units')->insertGetId([
        'name' => 'QA bottle', 'code' => 'bottle', 'is_active' => true,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $typeId = \Illuminate\Support\Facades\DB::table('cleaning_material_types')->insertGetId([
        'name' => 'QA room cleaner', 'cleaning_material_unit_id' => $unitId,
        'price_per_unit' => 7, 'is_active' => true,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $materialId = \Illuminate\Support\Facades\DB::table('cleaning_materials')->insertGetId([
        'name' => 'QA stocked cleaner', 'cleaning_material_type_id' => $typeId,
        'stock_quantity' => 20, 'is_active' => true,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    \Illuminate\Support\Facades\DB::table('cleaning_material_quantity_rules')->insert([
        'cleaning_material_id' => $materialId, 'quantity_per_room' => 1,
        'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
    ]);

    $service = \Modules\Cleaning\Models\CleaningSpecialService::query()->create([
        'name' => 'QA per period polish', 'pricing_unit' => 'piece',
        'base_unit_price' => 10, 'estimated_duration_minutes' => 5,
        'supports_dirtiness' => false, 'is_active' => true,
    ]);
    $data = roomPeriodPhaseOnePayload();
    $data['propertyDetails']['bedrooms'] = 2;
    $data['propertyDetails']['rooms'] = 2;
    $data['propertyDetails']['cleaning_mode'] = 'regular';
    foreach ($data['roomPeriods'] as &$period) {
        $period['specialServices'] = [[
            'specialServiceId' => (int) $service->id,
            'quantity' => $period['clientKey'] === 'bedroom.1' ? 2 : 1,
        ]];
    }
    unset($period);

    $estimate = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $data)->assertOk();
    $data['quoteSignature'] = $estimate->json('quoteSignature');
    $response = $this->postJson('/api/v1/user/cleaning/orders', $data)->assertCreated();
    $booking = CleaningBooking::query()->findOrFail((int) $response->json('order.id'));

    expect($booking->roomPeriods()->count())->toBe(2)
        ->and($booking->materials()->count())->toBe(1)
        ->and((float) $booking->materials()->sum('total_price'))->toBe(7.0)
        ->and($booking->specialServices()->count())->toBe(1)
        ->and((float) $booking->specialServices()->first()->total_price)->toBe(30.0)
        ->and($booking->specialServices()->first()->items()->count())->toBe(2)
        ->and((float) $booking->total_price)->toBe((float) $estimate->json('pricing.totalPrice'));

    $resource = (new \Modules\User\Http\Resources\UserCleaningBookingResource($booking))
        ->toArray(request());
    expect($resource['roomPeriods'])->toHaveCount(2)
        ->and($resource['roomPeriods'][0]['materials']['providedByPlatform'])->toBeTrue()
        ->and($resource['roomPeriods'][1]['materials']['providedByPlatform'])->toBeFalse()
        ->and($resource['capabilities']['roomPeriods'])->toBeTrue();
});
