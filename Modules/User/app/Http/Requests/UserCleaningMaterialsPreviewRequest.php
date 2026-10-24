<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Cleaning\Models\CleaningRoomType;

final class UserCleaningMaterialsPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'contractVersion' => ['required', 'integer', Rule::in([2])],
            'rooms' => ['required', 'array', 'min:1', 'max:30'],
            'rooms.*' => ['required', 'array:roomKey,roomType,size,cleaningMode'],
            'rooms.*.roomKey' => ['required', 'string', 'max:68', 'regex:/^[a-zA-Z0-9_.-]+$/D', 'distinct'],
            'rooms.*.roomType' => ['required', Rule::in(array_values(array_unique([...CleaningRoomType::availableCodes(), 'toilet'])))],
            'rooms.*.size' => ['required', Rule::in(['small', 'medium', 'large'])],
            'rooms.*.cleaningMode' => ['required', Rule::in(['regular', 'deep'])],
        ];
    }
}
