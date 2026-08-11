<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Modules\Cleaning\Http\Requests\EventBookingRequest;
use Modules\User\Http\Requests\UserCleaningOrderStoreRequest;
use Modules\User\Http\Requests\UserCleaningOrderUpdateRequest;

function bookingScheduleValidator(FormRequest $request): \Illuminate\Contracts\Validation\Validator
{
    return Validator::make($request->all(), $request->rules());
}

function makeBookingScheduleRequest(string $requestClass, string $method, array $payload): FormRequest
{
    /** @var FormRequest $request */
    $request = $requestClass::create('/test', $method, $payload);
    $request->setContainer(app());

    return $request;
}

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-26 10:00:00', config('app.timezone')));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('allows current date when creating regular cleaning orders', function (): void {
    $request = makeBookingScheduleRequest(UserCleaningOrderStoreRequest::class, 'POST', [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'address' => 'Damascus - Mazzeh',
            'rooms' => 2,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'living_room_size' => 'small',
        ],
        'scheduledDate' => now(config('app.timezone'))->toDateString(),
        'scheduledTime' => '12:00',
        'termsAccepted' => true,
    ]);

    $validator = bookingScheduleValidator($request);

    expect($validator->fails())->toBeFalse();
});

it('allows current date when creating event assistance orders through the user cleaning flow', function (): void {
    $request = makeBookingScheduleRequest(UserCleaningOrderStoreRequest::class, 'POST', [
        'propertyType' => 'event_assistance',
        'propertyDetails' => [
            'address' => 'Damascus - Event Hall',
            'eventType' => 'family_dinner',
            'guestCount' => 25,
            'venueType' => 'apartment',
            'customService' => 'تنظيف وترتيب المناسبة',
            'hours' => 4,
        ],
        'scheduledDate' => now(config('app.timezone'))->toDateString(),
        'scheduledTime' => '18:00',
        'termsAccepted' => true,
    ]);

    $validator = bookingScheduleValidator($request);

    expect($validator->fails())->toBeFalse();
});

it('rejects past dates when creating user cleaning orders', function (): void {
    $request = makeBookingScheduleRequest(UserCleaningOrderStoreRequest::class, 'POST', [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'address' => 'Damascus - Mazzeh',
            'rooms' => 2,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'living_room_size' => 'small',
        ],
        'scheduledDate' => now(config('app.timezone'))->subDay()->toDateString(),
        'scheduledTime' => '12:00',
        'termsAccepted' => true,
    ]);

    $validator = bookingScheduleValidator($request);

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('scheduledDate'))->toBeTrue();
});

it('allows current date when updating a user cleaning order schedule', function (): void {
    $request = makeBookingScheduleRequest(UserCleaningOrderUpdateRequest::class, 'PATCH', [
        'scheduledDate' => now(config('app.timezone'))->toDateString(),
        'scheduledTime' => '13:30',
    ]);

    $validator = bookingScheduleValidator($request);

    expect($validator->fails())->toBeFalse();
});

it('rejects past dates when updating a user cleaning order schedule', function (): void {
    $request = makeBookingScheduleRequest(UserCleaningOrderUpdateRequest::class, 'PATCH', [
        'scheduledDate' => now(config('app.timezone'))->subDay()->toDateString(),
        'scheduledTime' => '13:30',
    ]);

    $validator = bookingScheduleValidator($request);

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('scheduledDate'))->toBeTrue();
});

it('allows current date for event booking API validation', function (): void {
    $customer = User::factory()->create();

    $request = makeBookingScheduleRequest(EventBookingRequest::class, 'POST', [
        'customerId' => $customer->id,
        'scheduledDate' => now(config('app.timezone'))->toDateString(),
        'scheduledTime' => '19:00',
        'eventType' => 'birthday',
    ]);

    $validator = bookingScheduleValidator($request);

    expect($validator->fails())->toBeFalse();
});

it('rejects past dates for event booking API validation', function (): void {
    $customer = User::factory()->create();

    $request = makeBookingScheduleRequest(EventBookingRequest::class, 'POST', [
        'customerId' => $customer->id,
        'scheduledDate' => now(config('app.timezone'))->subDay()->toDateString(),
        'scheduledTime' => '19:00',
        'eventType' => 'birthday',
    ]);

    $validator = bookingScheduleValidator($request);

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('scheduledDate'))->toBeTrue();
});
