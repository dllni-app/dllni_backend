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
        $opaqueKey = ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9_.-]+$/D'];
        $roomKey = ['required', 'string', 'max:68', 'regex:/^[a-zA-Z0-9_.-]+$/D'];
        $scheduleKey = ['required', 'string', 'max:77', 'regex:/^[a-zA-Z0-9_.-]+$/D'];

        return [
            'contractVersion' => ['sometimes', 'integer', Rule::in([2])],
            'quoteId' => ['sometimes', 'uuid'],
            'quoteSignature' => ['sometimes', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/D'],
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
            'rooms.*.roomKey' => [...$roomKey, 'distinct'],
            'rooms.*.roomType' => ['required', Rule::in(CleaningRoomType::availableCodes())],
            'rooms.*.size' => ['required', Rule::in(['small', 'medium', 'large'])],
            'rooms.*.cleaningMode' => ['required', Rule::in(['regular', 'deep'])],
            'rooms.*.estimatedHours' => ['sometimes', 'numeric', 'min:0.25', 'max:24'],
            'recurrence' => ['sometimes', 'array:cadence'],
            'recurrence.cadence' => ['required_with:recurrence', Rule::in(['once', 'weekly', 'monthly', 'custom'])],
            'appointments' => ['required_if:contractVersion,2', 'array', 'min:1', 'max:30'],
            'appointments.*' => ['array:appointmentKey,sequence,roomSchedules,timePeriods'],
            'appointments.*.appointmentKey' => [...$opaqueKey, 'distinct'],
            'appointments.*.sequence' => ['required', 'integer', 'min:1', 'max:30', 'distinct'],
            'appointments.*.roomSchedules' => ['required', 'array', 'min:1', 'max:30'],
            'appointments.*.roomSchedules.*' => ['array:scheduleKey,roomKey,scheduledDate,scheduledTime,numberOfWorkers'],
            'appointments.*.roomSchedules.*.scheduleKey' => $scheduleKey,
            'appointments.*.roomSchedules.*.roomKey' => $roomKey,
            'appointments.*.roomSchedules.*.scheduledDate' => ['required', 'date_format:Y-m-d'],
            'appointments.*.roomSchedules.*.scheduledTime' => ['required', 'date_format:H:i'],
            'appointments.*.roomSchedules.*.numberOfWorkers' => ['required', 'integer', 'min:1', 'max:'.config('cleaning_booking_v2.max_workers', 20)],
            'appointments.*.timePeriods' => ['sometimes', 'array', 'max:30'],
            'appointments.*.timePeriods.*' => ['array:periodKey,roomScheduleKeys,materials,specialServices'],
            'appointments.*.timePeriods.*.periodKey' => $opaqueKey,
            'appointments.*.timePeriods.*.roomScheduleKeys' => ['required', 'array', 'min:1', 'max:30'],
            'appointments.*.timePeriods.*.roomScheduleKeys.*' => $scheduleKey,
            'appointments.*.timePeriods.*.materials' => ['required', 'array:choice,materialTypeIds'],
            'appointments.*.timePeriods.*.materials.choice' => ['required', Rule::in(['customer_all', 'customer_partial', 'platform_all'])],
            'appointments.*.timePeriods.*.materials.materialTypeIds' => ['present', 'array', 'max:64'],
            // Validate uniqueness per period below; material IDs can recur across independent appointments.
            'appointments.*.timePeriods.*.materials.materialTypeIds.*' => ['integer', Rule::exists('cleaning_material_types', 'id')->where('is_active', true)],
            'appointments.*.timePeriods.*.specialServices' => ['present', 'array', 'max:30'],
            'appointments.*.timePeriods.*.specialServices.*' => ['array:serviceKey,specialServiceId,targetRoomKeys,quantity,dirtinessLevel'],
            'appointments.*.timePeriods.*.specialServices.*.serviceKey' => $opaqueKey,
            'appointments.*.timePeriods.*.specialServices.*.specialServiceId' => ['required', 'integer', Rule::exists('cleaning_special_services', 'id')->where('is_active', true)],
            'appointments.*.timePeriods.*.specialServices.*.targetRoomKeys' => ['required', 'array', 'min:1', 'max:30'],
            'appointments.*.timePeriods.*.specialServices.*.targetRoomKeys.*' => $roomKey,
            'appointments.*.timePeriods.*.specialServices.*.quantity' => ['required', 'numeric', 'gt:0', 'max:10000'],
            'appointments.*.timePeriods.*.specialServices.*.dirtinessLevel' => ['present', 'nullable', Rule::in(['light', 'heavy'])],
        ];
    }

    public static function validate(array $data, Validator $validator, bool $creating = false): void
    {
        if ((int) ($data['contractVersion'] ?? 0) !== 2) {
            if (isset($data['rooms']) || isset($data['appointments'])) {
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
        $appointmentDates = [];
        foreach ($data['appointments'] as $i => $appointment) {
            $scheduledRooms = array_column($appointment['roomSchedules'], 'roomKey');
            $keys = array_column($appointment['roomSchedules'], 'scheduleKey');
            if (count($scheduledRooms) !== $rooms->count() || count(array_unique($scheduledRooms)) !== count($scheduledRooms) || array_diff($scheduledRooms, $rooms->keys()->all()) !== [] || count(array_unique($keys)) !== count($keys)) {
                $validator->errors()->add("appointments.$i.roomSchedules", 'حدد موعداً واحداً لكل غرفة في الزيارة.');
            }
            $periodKeys = array_column($appointment['timePeriods'] ?? [], 'periodKey');
            if (count(array_unique($periodKeys)) !== count($periodKeys)) {
                $validator->errors()->add("appointments.$i.timePeriods", 'معرف الفترة مكرر.');
            }
            foreach ($appointment['timePeriods'] ?? [] as $periodIndex => $period) {
                $ids = $period['materials']['materialTypeIds'] ?? [];
                if (is_array($ids) && count($ids) !== count(array_unique($ids))) {
                    $validator->errors()->add("appointments.$i.timePeriods.$periodIndex.materials.materialTypeIds", 'لا يمكن تكرار المادة ضمن نفس الفترة.');
                }
            }
            $dates = array_values(array_unique(array_column($appointment['roomSchedules'], 'scheduledDate')));
            if (count($dates) !== 1 || isset($appointmentDates[$dates[0]])) {
                $validator->errors()->add("appointments.$i.roomSchedules", 'كل زيارة في يوم واحد مختلف عن الزيارات الأخرى.');
            }
            if ($dates !== []) {
                $appointmentDates[$dates[0]] = true;
            }
        }
        $dates = array_keys($appointmentDates);
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
                    $validator->errors()->add('appointments', 'التاريخ الشهري المطلوب غير موجود في أحد الأشهر. اختر يوماً متاحاً.');
                    break;
                }
                if ($expected->toDateString() !== $date) {
                    $validator->errors()->add('appointments', 'تواريخ الزيارات لا تتطابق مع التكرار الأسبوعي أو الشهري المحدد.');
                    break;
                }
            }
        }        if (count($dates) > 1 && CarbonImmutable::parse($dates[0])->diffInDays(CarbonImmutable::parse(end($dates))) > 90) {
            $validator->errors()->add('appointments', 'يجب أن تقع جميع الزيارات ضمن فترة لا تتجاوز 90 يوماً.');
        }
    }
}
