<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\User\Services\UserCleaningOrderEstimationService;

trait ValidatesRoomCleaningModes
{
    /** @return array<string, mixed> */
    private function roomCleaningModeRules(): array
    {
        return [
            'propertyDetails.room_cleaning_modes' => ['sometimes', 'array', 'max:200'],
            'propertyDetails.room_cleaning_modes.*' => ['required', 'string', Rule::in(UserCleaningOrderEstimationService::CLEANING_MODES)],
        ];
    }

    private function validateRoomCleaningModes(Validator $validator): void
    {
        if (! $this->exists('propertyDetails.room_cleaning_modes')) {
            return;
        }

        $modes = $this->input('propertyDetails.room_cleaning_modes');
        if (! is_array($modes)) {
            return;
        }

        $propertyType = (string) $this->input('propertyType', '');
        if ($propertyType === UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE) {
            $validator->errors()->add('propertyDetails.room_cleaning_modes', 'لا تتوفر أنواع تنظيف الغرف لطلبات المناسبات.');

            return;
        }

        $breakdown = $this->input('propertyDetails.room_size_breakdown');
        if (! is_array($breakdown)) {
            $validator->errors()->add('propertyDetails.room_size_breakdown', 'يجب إرسال تفاصيل أحجام الغرف لتحديد نوع تنظيف كل غرفة.');

            return;
        }

        foreach ($modes as $key => $mode) {
            if (! is_string($key) || ! preg_match('/^(bedroom|bathroom|toilet|kitchen|living_room|balcony|corridor|shed)\.(small|medium|large)\.([1-9][0-9]{0,2})$/D', $key, $matches)) {
                $validator->errors()->add('propertyDetails.room_cleaning_modes', 'يوجد معرّف غرفة غير صالح.');

                continue;
            }
            $count = $breakdown[$matches[1]][$matches[2]] ?? 0;
            if (! is_numeric($count) || (int) $matches[3] > (int) $count) {
                $validator->errors()->add('propertyDetails.room_cleaning_modes', 'لا يمكن اختيار نوع تنظيف لغرفة غير مضافة إلى الطلب.');
            }
            if (! in_array($mode, UserCleaningOrderEstimationService::CLEANING_MODES, true)) {
                $validator->errors()->add('propertyDetails.room_cleaning_modes', 'اختر حوسة أو تعزيل لكل غرفة.');
            }
        }
    }
}
