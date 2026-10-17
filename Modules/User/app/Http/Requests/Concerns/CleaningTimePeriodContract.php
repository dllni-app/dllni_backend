<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Cleaning\Models\CleaningRoomType;

final class CleaningTimePeriodContract
{
    public static function rules(): array
    {
        $key = ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9_.-]+$/D'];

        return [
            'contractVersion' => ['sometimes', 'integer', Rule::in([2])],
            'quoteId' => ['sometimes', 'uuid'],
            'couponCode' => ['sometimes', 'nullable', 'string', 'max:50'],
            'idempotencyKey' => ['sometimes', 'uuid'],
            'neighborhoodId' => ['sometimes', 'nullable', 'integer', Rule::exists('cleaning_neighborhoods', 'id')->where('is_active', true)],
            'neighborhood' => ['sometimes', 'nullable', 'string', 'max:255'],
            'workEnvironmentConfirmation' => ['sometimes', 'array:beneficiaryPresence,pledgeAccepted,pledgeVersion'],
            'workEnvironmentConfirmation.beneficiaryPresence' => ['sometimes', 'string'],
            'workEnvironmentConfirmation.pledgeAccepted' => ['sometimes', 'boolean'],
            'workEnvironmentConfirmation.pledgeVersion' => ['sometimes', 'string', 'max:100'],
            'rooms' => ['required_if:contractVersion,2', 'array', 'min:1', 'max:30'],
            'rooms.*' => ['array:roomKey,roomType,size,cleaningMode,estimatedHours'],
            'rooms.*.roomKey' => [...$key, 'distinct'],
            'rooms.*.roomType' => ['required', Rule::in(CleaningRoomType::availableCodes())],
            'rooms.*.size' => ['required', Rule::in(['small', 'medium', 'large'])],
            'rooms.*.cleaningMode' => ['required', Rule::in(['regular', 'deep'])],
            'rooms.*.estimatedHours' => ['sometimes', 'numeric', 'min:0.25', 'max:24'],
            'recurrence' => ['sometimes', 'array:cadence'],
            'recurrence.cadence' => ['required_with:recurrence', Rule::in(['once', 'weekly', 'monthly', 'custom'])],            'visits' => ['required_if:contractVersion,2', 'array', 'min:1', 'max:30'],
            'visits.*' => ['array:visitKey,sequence,roomSchedules,timePeriods'],
            'visits.*.visitKey' => [...$key, 'distinct'],
            'visits.*.sequence' => ['required', 'integer', 'min:1', 'max:30', 'distinct'],
            'visits.*.roomSchedules' => ['required', 'array', 'min:1', 'max:30'],
            'visits.*.roomSchedules.*' => ['array:scheduleKey,roomKey,scheduledDate,scheduledTime,numberOfWorkers'],
            'visits.*.roomSchedules.*.scheduleKey' => $key,
            'visits.*.roomSchedules.*.roomKey' => $key,
            'visits.*.roomSchedules.*.scheduledDate' => ['required', 'date_format:Y-m-d'],
            'visits.*.roomSchedules.*.scheduledTime' => ['required', 'date_format:H:i'],
            'visits.*.roomSchedules.*.numberOfWorkers' => ['required', 'integer', 'min:1', 'max:'.config('cleaning_booking_v2.max_workers', 20)],
            'visits.*.timePeriods' => ['sometimes', 'array', 'max:30'],
            'visits.*.timePeriods.*' => ['array:periodKey,roomScheduleKeys,materials,specialServices'],
            'visits.*.timePeriods.*.periodKey' => $key,
            'visits.*.timePeriods.*.roomScheduleKeys' => ['required', 'array', 'min:1', 'max:30'],
            'visits.*.timePeriods.*.roomScheduleKeys.*' => $key,
            'visits.*.timePeriods.*.materials' => ['required', 'array:choice,materialTypeIds'],
            'visits.*.timePeriods.*.materials.choice' => ['required', Rule::in(['customer_all', 'customer_partial', 'platform_all'])],
            'visits.*.timePeriods.*.materials.materialTypeIds' => ['present', 'array', 'max:64'],
            // Validate uniqueness per period below; wildcard "distinct" incorrectly rejects IDs reused in another visit.
            'visits.*.timePeriods.*.materials.materialTypeIds.*' => ['integer', Rule::exists('cleaning_material_types', 'id')->where('is_active', true)],
            'visits.*.timePeriods.*.specialServices' => ['present', 'array', 'max:30'],
            'visits.*.timePeriods.*.specialServices.*' => ['array:serviceKey,specialServiceId,targetRoomKeys,quantity,dirtinessLevel'],
            'visits.*.timePeriods.*.specialServices.*.serviceKey' => $key,
            'visits.*.timePeriods.*.specialServices.*.specialServiceId' => ['required', 'integer', Rule::exists('cleaning_special_services', 'id')->where('is_active', true)],
            'visits.*.timePeriods.*.specialServices.*.targetRoomKeys' => ['required', 'array', 'min:1', 'max:30'],
            'visits.*.timePeriods.*.specialServices.*.targetRoomKeys.*' => $key,
            'visits.*.timePeriods.*.specialServices.*.quantity' => ['required', 'numeric', 'gt:0', 'max:10000'],
            'visits.*.timePeriods.*.specialServices.*.dirtinessLevel' => ['present', 'nullable', Rule::in(['light', 'heavy'])],
        ];
    }

    public static function validate(array $data, Validator $validator, bool $creating = false): void
    {
        if ((int) ($data['contractVersion'] ?? 0) !== 2) {
            if (isset($data['rooms']) || isset($data['visits'])) {
                $validator->errors()->add('contractVersion', 'إصدار الحجز غير صالح.');
            }

            return;
        }
        foreach (['roomPeriods', 'materials', 'requestMaterials', 'specialServices', 'workerRoomAssignments', 'openTime', 'event', 'schedule'] as $field) {
            if (array_key_exists($field, $data)) {
                $validator->errors()->add($field, 'استخدم اختيارات الفترة الزمنية لهذا الحجز.');
            }
        }
        if (($data['propertyType'] ?? '') === 'event_assistance' || ($data['bookingKind'] ?? 'standard') !== 'standard') {
            $validator->errors()->add('bookingKind', 'الفترات الزمنية مخصصة لتنظيف المنزل.');
        }
        if ($creating) {
            foreach (['quoteId', 'quoteSignature', 'idempotencyKey'] as $field) {
                if (empty($data[$field])) {
                    $validator->errors()->add($field, 'حدّث السعر قبل التأكيد.');
                }
            }
        }
        if (mb_trim((string) ($data['couponCode'] ?? '')) !== '') {
            $validator->errors()->add('couponCode', 'كوبونات الخصم غير متاحة لحجز الفترات حالياً. لم يتم تأكيد الحجز.');
        }
        if ($validator->errors()->isNotEmpty()) {
            return;
        }
        $rooms = collect($data['rooms'])->keyBy('roomKey');
        $actual = [];
        foreach ($rooms as $room) {
            $actual[$room['roomType']][$room['size']] = ($actual[$room['roomType']][$room['size']] ?? 0) + 1;
        }
        foreach (array_unique([...array_keys($actual), ...array_keys((array) ($data['propertyDetails']['room_size_breakdown'] ?? []))]) as $type) {
            foreach (['small', 'medium', 'large'] as $size) {
                if (($actual[$type][$size] ?? 0) !== (int) ($data['propertyDetails']['room_size_breakdown'][$type][$size] ?? 0)) {
                    $validator->errors()->add('rooms', 'الغرف لا تطابق المساحات المختارة.');
                }
            }
        }
        $visitDates = [];
        foreach ($data['visits'] as $i => $visit) {
            $scheduledRooms = array_column($visit['roomSchedules'], 'roomKey');
            $keys = array_column($visit['roomSchedules'], 'scheduleKey');
            if (count($scheduledRooms) !== $rooms->count() || count(array_unique($scheduledRooms)) !== count($scheduledRooms) || array_diff($scheduledRooms, $rooms->keys()->all()) !== [] || count(array_unique($keys)) !== count($keys)) {
                $validator->errors()->add("visits.$i.roomSchedules", 'حدد موعداً واحداً لكل غرفة في الزيارة.');
            }
            $periodKeys = array_column($visit['timePeriods'] ?? [], 'periodKey');
            if (count(array_unique($periodKeys)) !== count($periodKeys)) {
                $validator->errors()->add("visits.$i.timePeriods", 'معرف الفترة مكرر.');
            }
            foreach ($visit['timePeriods'] ?? [] as $periodIndex => $period) {
                $ids = $period['materials']['materialTypeIds'] ?? [];
                if (is_array($ids) && count($ids) !== count(array_unique($ids))) {
                    $validator->errors()->add("visits.$i.timePeriods.$periodIndex.materials.materialTypeIds", 'لا يمكن تكرار المادة ضمن نفس الفترة.');
                }
            }
            $dates = array_values(array_unique(array_column($visit['roomSchedules'], 'scheduledDate')));
            if (count($dates) !== 1 || isset($visitDates[$dates[0]])) {
                $validator->errors()->add("visits.$i.roomSchedules", 'كل زيارة في يوم واحد مختلف عن الزيارات الأخرى.');
            }
            if ($dates !== []) {
                $visitDates[$dates[0]] = true;
            }
        }
        $dates = array_keys($visitDates);
        sort($dates);
        $cadence = $data['recurrence']['cadence'] ?? null;
        if ($cadence === 'once' && count($dates) !== 1) {
            $validator->errors()->add('recurrence.cadence', 'الحجز لمرة واحدة يجب أن يحتوي على زيارة واحدة.');
        }
        if (in_array($cadence, ['weekly', 'monthly'], true) && $dates !== []) {
            $first = CarbonImmutable::parse($dates[0]);
            foreach ($dates as $index => $date) {
                $expected = $cadence === 'weekly'
                    ? $first->addWeeks($index)
                    : $first->addMonthsNoOverflow($index);
                if ($cadence === 'monthly' && $expected->day !== $first->day) {
                    $validator->errors()->add('visits', 'التاريخ الشهري المطلوب غير موجود في أحد الأشهر. اختر يوماً متاحاً.');
                    break;
                }
                if ($expected->toDateString() !== $date) {
                    $validator->errors()->add('visits', 'تواريخ الزيارات لا تتطابق مع التكرار الأسبوعي أو الشهري المحدد.');
                    break;
                }
            }
        }        if (count($dates) > 1 && CarbonImmutable::parse($dates[0])->diffInDays(CarbonImmutable::parse(end($dates))) > 90) {
            $validator->errors()->add('visits', 'يجب أن تقع جميع الزيارات ضمن فترة لا تتجاوز 90 يوماً.');
        }
    }
}
