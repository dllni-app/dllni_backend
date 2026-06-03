<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CleaningBookingRoomClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'roomIds' => ['sometimes', 'array', 'min:1'],
            'roomIds.*' => ['integer', 'distinct', 'exists:cleaning_booking_rooms,id'],
        ];
    }
}
