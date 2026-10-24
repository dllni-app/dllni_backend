<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Modules\User\Http\Requests\Concerns\ValidatesRoomCleaningModes;

class RoomModeValidationProbeV2 extends FormRequest
{
    use ValidatesRoomCleaningModes {
        roomCleaningModeRules as public;
        validateRoomCleaningModes as public;
    }
}

function roomModeValidatorV2(array $propertyDetails, string $propertyType = 'apartment'): \Illuminate\Contracts\Validation\Validator
{
    $request = RoomModeValidationProbeV2::create('/', 'POST', [
        'propertyType' => $propertyType,
        'propertyDetails' => $propertyDetails,
    ]);
    $validator = Validator::make($request->all(), $request->roomCleaningModeRules());
    $request->validateRoomCleaningModes($validator);

    return $validator;
}

it('accepts only valid mode values for existing room keys', function (): void {
    $details = [
        'room_size_breakdown' => [
            'bedroom' => ['small' => 1],
        ],
        'room_cleaning_modes' => [
            'bedroom.small.1' => 'deep',
        ],
    ];
    expect(roomModeValidatorV2($details)->errors()->isEmpty())->toBeTrue();

    $details['room_cleaning_modes'] = ['bedroom.small.1' => 'ultra'];
    expect(roomModeValidatorV2($details)->fails())->toBeTrue();
});

it('rejects a mode entry for a room not in the breakdown', function (): void {
    $validator = roomModeValidatorV2([
        'room_size_breakdown' => ['bedroom' => ['small' => 1]],
        'room_cleaning_modes' => ['bedroom.small.2' => 'deep'],
    ]);
    expect($validator->errors()->has('propertyDetails.room_cleaning_modes'))->toBeTrue();
});

it('prohibits room mode overrides on event orders', function (): void {
    $validator = roomModeValidatorV2([
        'room_size_breakdown' => ['bedroom' => ['small' => 1]],
        'room_cleaning_modes' => ['bedroom.small.1' => 'deep'],
    ], 'event_assistance');
    expect($validator->errors()->has('propertyDetails.room_cleaning_modes'))->toBeTrue();
});
