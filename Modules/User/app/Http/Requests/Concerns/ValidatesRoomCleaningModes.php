<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Cleaning\Models\CleaningRoomType;
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

    /** @return array<string, mixed> */
    private function roomEstimatedHoursRules(): array
    {
        return [
            'propertyDetails.room_estimated_hours' => ['sometimes', 'array', 'max:200'],
            'propertyDetails.room_estimated_hours.*' => ['required', 'numeric', 'min:0.25', 'max:24'],
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
            if (! is_string($key) || ! preg_match('/^([a-z][a-z0-9_]*)\.(small|medium|large)\.([1-9][0-9]{0,2})$/D', $key, $matches) || ! in_array($matches[1] ?? '', CleaningRoomType::availableCodes(), true)) {
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

    private function validateRoomEstimatedHours(Validator $validator): void
    {
        $hours = $this->input('propertyDetails.room_estimated_hours');
        if ($hours === null) {
            return;
        }
        if (! is_array($hours) || (string) $this->input('propertyType') === UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE) {
            $validator->errors()->add('propertyDetails.room_estimated_hours', 'ساعات الغرف متاحة لطلبات تنظيف المنزل فقط.');

            return;
        }
        $breakdown = $this->input('propertyDetails.room_size_breakdown');
        foreach ($hours as $key => $value) {
            if (! is_string($key)
                || ! preg_match('/^([a-z][a-z0-9_]*)\.(small|medium|large)\.([1-9][0-9]{0,2})$/D', $key, $parts)
                || ! in_array($parts[1] ?? '', CleaningRoomType::availableCodes(), true)
                || ! is_array($breakdown)
                || (int) ($breakdown[$parts[1]][$parts[2]] ?? 0) < (int) ($parts[3] ?? 1)
                || ! is_numeric($value) || (float) $value < 0.25 || (float) $value > 24) {
                $validator->errors()->add('propertyDetails.room_estimated_hours', 'يجب أن تكون ساعات الغرفة بين ربع ساعة و24 ساعة، وأن تشير إلى غرفة موجودة ونشطة.');
            }
        }
    }

    private function validateActiveRoomTypes(Validator $validator): void
    {
        $active = CleaningRoomType::availableCodes();
        $breakdown = $this->input('propertyDetails.room_size_breakdown', []);
        if (is_array($breakdown)) {
            foreach ($breakdown as $code => $sizes) {
                if (is_array($sizes) && array_sum(array_map('intval', $sizes)) > 0
                    && ! in_array($code, $active, true)) {
                    $validator->errors()->add('propertyDetails.room_size_breakdown', 'تم إيقاف أحد أنواع الغرف. حدّث القائمة قبل إتمام الحجز.');
                }
            }
        }
        foreach (['rooms', 'roomPeriods'] as $field) {
            $rooms = $this->input($field, []);
            if (! is_array($rooms)) {
                continue;
            }
            foreach ($rooms as $index => $room) {
                if (is_array($room) && isset($room['roomType']) && ! in_array($room['roomType'], $active, true)) {
                    $validator->errors()->add("{$field}.{$index}.roomType", 'نوع الغرفة غير متاح حالياً.');
                }
            }
        }
    }
}
