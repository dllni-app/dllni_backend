<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningRoomType;
use Modules\Cleaning\Models\CleaningSpecialService;
use Modules\User\Http\Requests\Concerns\CleaningTimePeriodContract;
use Modules\User\Http\Requests\Concerns\RoomPeriodContract;
use Modules\User\Http\Requests\UserCleaningMaterialsPreviewRequest;
use Modules\User\Http\Requests\UserCleaningOrderEstimatePriceRequest;

function cleaningRoomCodeIdentityCode(int $length = 64): string
{
    return 'a'.str_repeat('b', $length - 1);
}

function cleaningRoomCodeIdentityPeriodValidator(array $payload): Illuminate\Validation\Validator
{
    $validator = Validator::make($payload, RoomPeriodContract::rules());
    $validator->after(static fn ($validator) => RoomPeriodContract::validateConsistency($payload, $validator));

    return $validator;
}

it('accepts the maximum canonical room code and rejects a mismatched type prefix', function (): void {
    $code = 'a'.str_repeat('b', 60).'-9x';
    CleaningRoomType::query()->create([
        'code' => $code,
        'name' => 'نوع اختباري',
        'regular_hourly_rate' => 100,
        'deep_hourly_rate' => 200,
        'suggested_minutes_by_size' => ['small' => ['regular' => 30, 'deep' => 60]],
        'sort_order' => 99,
        'is_active' => true,
    ]);
    $key = $code.'.1';
    expect(mb_strlen($code.'.999'))->toBe(68);
    $payload = [
        'propertyType' => 'apartment', 'bookingKind' => 'standard',
        'assignmentMode' => 'open_count',
        'propertyDetails' => ['room_size_breakdown' => [$code => ['small' => 1]]],
        'roomPeriods' => [[
            'clientKey' => $key, 'visitSequence' => 1, 'roomType' => $code,
            'size' => 'small', 'cleaningMode' => 'regular', 'scheduledDate' => now()->addDays(3)->toDateString(),
            'scheduledTime' => '09:00', 'numberOfWorkers' => 1,
            'materials' => ['providedByPlatform' => true, 'materialTypeIds' => []],
            'specialServices' => [],
        ]],
    ];

    $validator = cleaningRoomCodeIdentityPeriodValidator($payload);
    expect($validator->passes())->toBeTrue($validator->errors()->toJson());
    $maximumKeyValidator = Validator::make(['roomPeriods' => [['clientKey' => $code.'.999']]], [
        'roomPeriods.0.clientKey' => RoomPeriodContract::rules()['roomPeriods.*.clientKey'],
    ]);
    expect($maximumKeyValidator->passes())->toBeTrue();
    $payload['roomPeriods'][0]['roomType'] = 'bedroom';
    expect(cleaningRoomCodeIdentityPeriodValidator($payload)->errors()->has('roomPeriods.0.roomType'))->toBeTrue();
});

it('parses custom hyphenated room mode and hour keys only for active catalog codes', function (): void {
    $code = 'a'.str_repeat('b', 60).'-9x';
    $roomType = CleaningRoomType::query()->create([
        'code' => $code, 'name' => 'نوع اختباري', 'regular_hourly_rate' => 100,
        'deep_hourly_rate' => 200, 'suggested_minutes_by_size' => [], 'sort_order' => 99, 'is_active' => true,
    ]);
    $key = $code.'.small.1';
    $payload = [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'room_size_breakdown' => [$code => ['small' => 1]],
            'room_cleaning_modes' => [$key => 'deep'],
            'room_estimated_hours' => [$key => 1.5],
        ],
    ];
    $request = UserCleaningOrderEstimatePriceRequest::create('/api/v1/user/cleaning/orders/estimate-price', 'POST', $payload);
    $request->setContainer(app())->setRedirector(app('redirect'));
    $validator = Validator::make($request->all(), $request->rules());
    $request->withValidator($validator);
    expect($validator->passes())->toBeTrue($validator->errors()->toJson());

    $payload['propertyDetails']['room_cleaning_modes'] = [$code.'.small.1.extra' => 'regular'];
    $malformed = UserCleaningOrderEstimatePriceRequest::create('/api/v1/user/cleaning/orders/estimate-price', 'POST', $payload);
    $malformed->setContainer(app())->setRedirector(app('redirect'));
    $malformedValidator = Validator::make($malformed->all(), $malformed->rules());
    $malformed->withValidator($malformedValidator);
    expect($malformedValidator->errors()->has('propertyDetails.room_cleaning_modes'))->toBeTrue();

    $roomType->update(['is_active' => false]);
    $archivedPayload = [
        'contractVersion' => 2,
        'rooms' => [['roomKey' => $code.'.1', 'roomType' => $code, 'size' => 'small', 'cleaningMode' => 'regular']],
        'visits' => [],
    ];
    $archivedRequest = UserCleaningOrderEstimatePriceRequest::create('/api/v1/user/cleaning/orders/estimate-price', 'POST', $archivedPayload);
    $archivedRequest->setContainer(app())->setRedirector(app('redirect'));
    $archivedValidator = Validator::make($archivedRequest->all(), $archivedRequest->rules());
    expect($archivedValidator->errors()->has('rooms.0.roomType'))->toBeTrue();

    $archivedPreview = UserCleaningMaterialsPreviewRequest::create('/api/v1/user/cleaning/orders/materials-preview', 'POST', [
        'contractVersion' => 2,
        'rooms' => [['roomKey' => $code.'.1', 'roomType' => $code, 'size' => 'small', 'cleaningMode' => 'regular']],
    ]);
    $archivedPreview->setContainer(app())->setRedirector(app('redirect'));
    $archivedPreviewValidator = Validator::make($archivedPreview->all(), $archivedPreview->rules());
    expect($archivedPreviewValidator->fails())->toBeTrue();
});

it('keeps opaque time-period keys at 64 while allowing 68 room references and 77 schedule keys', function (): void {
    $code = cleaningRoomCodeIdentityCode(64);
    $specialService = CleaningSpecialService::query()->create([
        'name' => 'خدمة اختبار', 'pricing_unit' => 'piece', 'unit_code' => 'piece', 'input_type' => 'quantity',
        'base_unit_price' => 25, 'estimated_duration_minutes' => 60, 'supports_dirtiness' => false, 'is_active' => true,
    ]);
    CleaningRoomType::query()->create([
        'code' => $code, 'name' => 'نوع اختباري', 'regular_hourly_rate' => 100,
        'deep_hourly_rate' => 200, 'suggested_minutes_by_size' => [], 'sort_order' => 99, 'is_active' => true,
    ]);
    $roomKey = $code.'.999';
    $scheduleKey = 'visit.30.'.$roomKey;
    expect(mb_strlen($roomKey))->toBe(68)->and(mb_strlen($scheduleKey))->toBe(77);

    $payload = [
        'contractVersion' => 2,
        'propertyDetails' => ['room_size_breakdown' => [$code => ['small' => 1]]],
        'rooms' => [['roomKey' => $roomKey, 'roomType' => cleaningRoomCodeIdentityCode(), 'size' => 'small', 'cleaningMode' => 'regular']],
        'visits' => [[
            'visitKey' => 'visit.30', 'sequence' => 30,
            'roomSchedules' => [['scheduleKey' => $scheduleKey, 'roomKey' => $roomKey, 'scheduledDate' => now()->addDays(3)->toDateString(), 'scheduledTime' => '09:00', 'numberOfWorkers' => 1]],
            'timePeriods' => [[
                'periodKey' => str_repeat('p', 64),
                'roomScheduleKeys' => [$scheduleKey],
                'materials' => ['choice' => 'customer_all', 'materialTypeIds' => []],
                'specialServices' => [[
                    'serviceKey' => str_repeat('s', 64), 'specialServiceId' => $specialService->id,
                    'targetRoomKeys' => [$roomKey], 'quantity' => 1, 'dirtinessLevel' => null,
                ]],
            ]],
        ]],
    ];

    $validator = Validator::make($payload, CleaningTimePeriodContract::rules());
    CleaningTimePeriodContract::validate($payload, $validator);
    expect($validator->passes())->toBeTrue($validator->errors()->toJson());
});

it('accepts and stores a signed room-period estimate with the maximum canonical key', function (): void {
    $code = 'a'.str_repeat('b', 60).'-9x';
    CleaningRoomType::query()->create([
        'code' => $code, 'name' => 'نوع غرف طويل', 'regular_hourly_rate' => 100,
        'deep_hourly_rate' => 200,
        'suggested_minutes_by_size' => ['small' => ['regular' => 30, 'deep' => 60]],
        'sort_order' => 99, 'is_active' => true,
    ]);
    Sanctum::actingAs(User::factory()->create());
    $date = now('Asia/Damascus')->addDays(3)->toDateString();
    $input = [
        'propertyType' => 'apartment', 'bookingKind' => 'standard', 'assignmentMode' => 'open_count',
        'propertyDetails' => [
            'address' => 'عنوان اختبار', 'bedrooms' => 1, 'rooms' => 1,
            'room_size_breakdown' => [$code => ['small' => 1]],
        ],
        'scheduledDate' => $date, 'scheduledTime' => '09:00',
        'addressLatitude' => 33.5138, 'addressLongitude' => 36.2765,
        'numberOfWorkers' => 1, 'termsAccepted' => true,
        'roomPeriods' => [[
            'clientKey' => $code.'.1', 'visitSequence' => 1, 'roomType' => $code,
            'size' => 'small', 'cleaningMode' => 'regular', 'scheduledDate' => $date,
            'scheduledTime' => '09:00', 'numberOfWorkers' => 1,
            'materials' => ['providedByPlatform' => true, 'materialTypeIds' => []], 'specialServices' => [],
        ]],
    ];

    $quote = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)
        ->assertOk()->assertJsonPath('roomPeriods.0.clientKey', $code.'.1')->json();
    $stored = $this->postJson('/api/v1/user/cleaning/orders', [...$input, 'quoteSignature' => $quote['quoteSignature']])
        ->assertCreated()->json();

    $this->assertDatabaseHas('cleaning_booking_room_periods', [
        'cleaning_booking_id' => $stored['order']['id'], 'client_key' => $code.'.1', 'room_type' => $code,
    ]);

    $booking = CleaningBooking::query()->findOrFail($stored['order']['id']);
    $approvedCustomerTotal = (float) $booking->total_price;
    expect($approvedCustomerTotal)->toBe((float) $quote['pricing']['totalPrice']);

    // Catalog edits affect later quotes, never the signed price already approved.
    CleaningRoomType::query()->where('code', $code)->update(['regular_hourly_rate' => 400]);
    $laterQuote = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)
        ->assertOk()->json();
    expect((float) $laterQuote['pricing']['totalPrice'])->toBeGreaterThan((float) $quote['pricing']['totalPrice'])
        ->and((float) $booking->fresh()->total_price)->toBe($approvedCustomerTotal);
});

it('accepts a 77-character generated time-period schedule key through signed estimate and create', function (): void {
    $code = cleaningRoomCodeIdentityCode(64);
    CleaningRoomType::query()->create([
        'code' => $code, 'name' => 'نوع غرف طويل', 'regular_hourly_rate' => 100,
        'deep_hourly_rate' => 200,
        'suggested_minutes_by_size' => ['small' => ['regular' => 30, 'deep' => 60]],
        'sort_order' => 99, 'is_active' => true,
    ]);
    Sanctum::actingAs(User::factory()->create());
    $date = now('Asia/Damascus')->addDays(3)->toDateString();
    $roomKey = $code.'.999';
    $scheduleKey = 'visit.30.'.$roomKey;
    $input = [
        'contractVersion' => 2, 'propertyType' => 'apartment', 'bookingKind' => 'standard',
        'assignmentMode' => 'open_count', 'genderPreference' => 'any',
        'propertyDetails' => ['address' => 'عنوان اختبار', 'room_size_breakdown' => [$code => ['small' => 1]]],
        'addressLatitude' => 33.5138, 'addressLongitude' => 36.2765,
        'rooms' => [['roomKey' => $roomKey, 'roomType' => $code, 'size' => 'small', 'cleaningMode' => 'regular']],
        'visits' => [[
            'visitKey' => 'visit.30', 'sequence' => 30,
            'roomSchedules' => [[
                'scheduleKey' => $scheduleKey, 'roomKey' => $roomKey,
                'scheduledDate' => $date, 'scheduledTime' => '09:00', 'numberOfWorkers' => 1,
            ]],
            'timePeriods' => [[
                'periodKey' => str_repeat('p', 64),
                'roomScheduleKeys' => [$scheduleKey],
                'materials' => ['choice' => 'customer_all', 'materialTypeIds' => []],
                'specialServices' => [],
            ]],
        ]],
    ];

    $quote = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $input)
        ->assertOk()->assertJsonPath('visits.0.roomSchedules.0.scheduleKey', $scheduleKey)->json();
    $payload = [...$input, 'quoteId' => $quote['quoteId'], 'quoteSignature' => $quote['quoteSignature'],
        'idempotencyKey' => (string) Illuminate\Support\Str::uuid(), 'termsAccepted' => true];
    $created = $this->postJson('/api/v1/user/cleaning/orders', $payload)->assertCreated()->json();

    $this->assertDatabaseHas('cleaning_room_schedules', [
        'cleaning_booking_id' => $created['order']['id'], 'schedule_key' => $scheduleKey,
    ]);
    $this->assertDatabaseHas('cleaning_booking_rooms', [
        'cleaning_booking_id' => $created['order']['id'], 'client_key' => $roomKey, 'room_type' => $code,
    ]);
});

it('accepts active catalog preview types and the legacy toilet type with a 68-character room key', function (): void {
    $code = 'a'.str_repeat('b', 60).'-9x';
    CleaningRoomType::query()->create([
        'code' => $code, 'name' => 'نوع اختباري', 'regular_hourly_rate' => 100,
        'deep_hourly_rate' => 200, 'suggested_minutes_by_size' => [], 'sort_order' => 99, 'is_active' => true,
    ]);
    $request = UserCleaningMaterialsPreviewRequest::create('/api/v1/user/cleaning/orders/materials-preview', 'POST', [
        'contractVersion' => 2,
        'rooms' => [
            ['roomKey' => $code.'.999', 'roomType' => $code, 'size' => 'small', 'cleaningMode' => 'regular'],
            ['roomKey' => 'toilet.1', 'roomType' => 'toilet', 'size' => 'small', 'cleaningMode' => 'regular'],
        ],
    ]);
    $request->setContainer(app())->setRedirector(app('redirect'));
    expect(Validator::make($request->all(), $request->rules())->passes())->toBeTrue();

    Sanctum::actingAs(User::factory()->create());
    $this->postJson('/api/v1/user/cleaning/orders/materials-preview', $request->all())->assertOk();
});
