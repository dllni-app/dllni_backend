<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Cleaning\Models\CleaningSpecialService;
use Modules\Cleaning\Services\CleaningMaterialQuoteService;
use Modules\Cleaning\Services\CleaningPricingCalculator;
use Modules\Cleaning\Services\CleaningSpecialServiceQuoteService;
use Modules\Cleaning\Services\CleaningSupervisorHourlyPricing;
use Modules\Cleaning\Support\CleaningRuntimeSettings;

final class CleaningTimePeriodPricingService
{
    public const ALGORITHM = 'time-period-v2-2026-10-15';

    public function __construct(
        private readonly UserCleaningOrderEstimationService $estimator,
        private readonly CleaningPricingCalculator $calculator,
        private readonly CleaningMaterialQuoteService $materials,
        private readonly CleaningSpecialServiceQuoteService $services,
        private readonly CleaningTimePeriodGrouper $grouper,
    ) {}

    public function quote(array $input): array
    {
        try {
            return $this->calculate($input);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['appointments' => ['تعذر تسعير الاختيارات الحالية. راجع المواد والخدمات المختارة.']]);
        }
    }

    private function calculate(array $input): array
    {
        $rooms = collect($input['rooms'])->keyBy('roomKey');
        $catalog = CleaningSpecialService::query()->where('is_active', true)->with(['dirtinessLevels', 'dirtinessRules'])->get()->keyBy('id');
        $appointments = [];
        $allSchedules = [];
        $base = $specialTotal = 0.0;
        $kits = [];
        $reconciliation = false;
        $timezone = (string) config('cleaning_booking_v2.timezone');
        foreach ($input['appointments'] as $appointmentIndex => $appointment) {
            $schedules = [];
            $periodServices = [];
            $addedMinutes = [];
            $seenTargets = [];
            foreach ($appointment['timePeriods'] ?? [] as $periodIndex => $period) {
                $serviceKeys = [];
                $memberRooms = collect($appointment['roomSchedules'])->whereIn('scheduleKey', $period['roomScheduleKeys'])->pluck('roomKey')->all();
                foreach ($period['specialServices'] as $service) {
                    if (isset($serviceKeys[$service['serviceKey']])) {
                        $this->invalid("appointments.$appointmentIndex.timePeriods.$periodIndex", 'معرف الخدمة مكرر.');
                    }
                    $serviceKeys[$service['serviceKey']] = true;
                    $entry = $catalog->get($service['specialServiceId']);
                    if ($entry === null || array_diff($service['targetRoomKeys'], $memberRooms) !== [] || count(array_unique($service['targetRoomKeys'])) !== count($service['targetRoomKeys'])) {
                        $this->invalid("appointments.$appointmentIndex.timePeriods.$periodIndex", 'حدد الغرف المستهدفة من داخل الفترة.');
                    }
                    $unit = (string) ($entry->unit_code ?: $entry->pricing_unit);
                    if (in_array($unit, ['piece', 'item', 'count', 'room', 'seat', 'window', 'door', 'unit'], true) && floor((float) $service['quantity']) !== (float) $service['quantity']) {
                        $this->invalid("appointments.$appointmentIndex.timePeriods.$periodIndex", 'تتطلب هذه الخدمة عدداً صحيحاً.');
                    }
                    if ((bool) $entry->supports_dirtiness && ! in_array($service['dirtinessLevel'], ['light', 'heavy'], true)) {
                        $this->invalid("appointments.$appointmentIndex.timePeriods.$periodIndex", 'اختر اتساخاً بسيطاً أو شديداً.');
                    }
                    if (! (bool) $entry->supports_dirtiness && $service['dirtinessLevel'] !== null) {
                        $this->invalid("appointments.$appointmentIndex.timePeriods.$periodIndex", 'هذه الخدمة لا تتطلب اختيار الاتساخ.');
                    }
                    $request = ['specialServiceId' => $entry->id, 'quantity' => $service['quantity'], 'dirtinessLevel' => $service['dirtinessLevel']];
                    $level = $entry->dirtinessLevels->firstWhere('slug', $service['dirtinessLevel']);
                    if ((bool) $entry->supports_dirtiness && $level !== null) {
                        $request['items'] = [['quantity' => $service['quantity'], 'dirtinessLevelId' => $level->id]];
                    }
                    $line = $this->services->quote([$request])['lines'][0];
                    foreach ($service['targetRoomKeys'] as $roomKey) {
                        $target = $entry->id.':'.$roomKey;
                        if (isset($seenTargets[$target])) {
                            $this->invalid("appointments.$appointmentIndex.timePeriods.$periodIndex", 'الخدمة مكررة للغرفة نفسها.');
                        }
                        $seenTargets[$target] = true;
                        $addedMinutes[$roomKey] = ($addedMinutes[$roomKey] ?? 0) + (int) ceil(max(0, (int) $entry->estimated_duration_minutes) * (float) $service['quantity']);
                        $specialTotal += (float) $line['totalPrice'];
                        $periodServices[$period['periodKey']][] = [...$service, 'targetRoomKey' => $roomKey, 'quoteLine' => $line, 'unit' => $unit];
                    }
                }
            }
            foreach ($appointment['roomSchedules'] as $scheduleIndex => $schedule) {
                $room = $rooms->get($schedule['roomKey']);
                if ($room === null) {
                    $this->invalid('rooms', 'الغرفة غير موجودة في الحجز.');
                }
                $singleRoomDetails = [
                    'cleaning_mode' => $room['cleaningMode'],
                    'room_size_breakdown' => [$room['roomType'] => [$room['size'] => 1]],
                ];
                if (isset($room['estimatedHours'])) {
                    $singleRoomDetails['room_estimated_hours'] = [$room['roomType'].'.'.$room['size'].'.1' => $room['estimatedHours']];
                }
                $single = $this->estimator->price((string) $input['propertyType'], $singleRoomDetails, null, null, null, null, false, [], (int) $schedule['numberOfWorkers']);
                $line = $single['roomPricingLines'][0] ?? null;
                if ($line === null) {
                    $this->invalid('rooms', 'لا يتوفر تسعير لهذه الغرفة.');
                }
                $work = (int) $line['totalMinutes'] + ($addedMinutes[$schedule['roomKey']] ?? 0);
                $duration = max(1, (int) ceil($work / (int) $schedule['numberOfWorkers']));
                $start = CarbonImmutable::createFromFormat('!Y-m-d H:i', $schedule['scheduledDate'].' '.$schedule['scheduledTime'], $timezone);
                $end = $start->addMinutes($duration);
                if ($start->lessThanOrEqualTo(CarbonImmutable::now($timezone)) || $end->toDateString() !== $start->toDateString()) {
                    $this->invalid("appointments.$appointmentIndex.roomSchedules.$scheduleIndex.scheduledTime", 'موعد الغرفة غير متاح. اختر موعداً مستقبلياً ينتهي في اليوم نفسه.');
                }
                if ($duration > (int) config('cleaning_booking_v2.max_duration_minutes')) {
                    $this->invalid("appointments.$appointmentIndex.roomSchedules.$scheduleIndex.numberOfWorkers", 'مدة الغرفة تتجاوز 8 ساعات لكل عامل. زد عدد العمال.');
                }
                $base += (float) $line['totalPrice'];
                $schedules[] = [...$schedule, 'estimatedWorkMinutes' => $work, 'estimatedDurationMinutes' => $duration, 'startAt' => $start->toIso8601String(), 'endAt' => $end->toIso8601String(), 'basePrice' => (float) $line['totalPrice']];
            }
            $groups = $this->grouper->group($schedules);
            $supplied = $appointment['timePeriods'] ?? [];
            $sources = [];
            foreach ($supplied as $period) {
                $members = $period['roomScheduleKeys'];
                sort($members, SORT_STRING);
                $membership = implode('|', $members);
                if (isset($sources[$membership]) || count(array_unique($members)) !== count($members)) {
                    $this->invalid("appointments.$appointmentIndex.timePeriods", 'كل موعد غرفة يتبع فترة واحدة فقط.');
                }
                $sources[$membership] = $period;
            }
            $canonicalMemberships = array_map(static fn (array $group): string => implode('|', $group['roomScheduleKeys']), $groups);
            $needsReview = $supplied !== [] && (count($sources) !== count($groups) || array_diff(array_keys($sources), $canonicalMemberships) !== []);
            $reconciliation = $reconciliation || $needsReview;
            foreach ($groups as &$group) {
                $membership = implode('|', $group['roomScheduleKeys']);
                $source = $needsReview ? null : ($sources[$membership] ?? null);
                $group['periodKey'] = $source['periodKey'] ?? $group['periodKey'];
                $group['materials'] = $source['materials'] ?? ['choice' => 'customer_all', 'materialTypeIds' => []];
                $group['specialServices'] = $source['specialServices'] ?? [];
                $group['serviceLines'] = $source === null ? [] : ($periodServices[$source['periodKey']] ?? []);
                $previewKits = [];
                $preview = $group;
                $preview['materials'] = ['choice' => 'platform_all', 'materialTypeIds' => []];
                $group['materialsPreview'] = array_map(static function (array $line): array {
                    unset($line['unitPrice'], $line['totalPrice']);

                    return $line;
                }, $this->periodMaterials($preview, $schedules, $rooms->all(), $appointment['appointmentKey'], $previewKits));
                $group['materialLines'] = $this->periodMaterials($group, $schedules, $rooms->all(), $appointment['appointmentKey'], $kits);
            }
            unset($group);
            array_push($allSchedules, ...$schedules);
            $appointments[] = ['appointmentKey' => $appointment['appointmentKey'], 'sequence' => (int) $appointment['sequence'], 'roomSchedules' => $schedules, 'timePeriods' => $groups, 'requiredSimultaneousWorkers' => $this->grouper->peakWorkers($schedules), 'reconciliationRequired' => $needsReview, 'reconciliationSources' => $needsReview ? $supplied : []];
        }
        $peak = $this->grouper->peakWorkers($allSchedules);
        // The customer chooses the team size; it must not equal the number of
        // room schedules or recurring appointments. Only actual capacity matters.
        $requestedWorkers = (int) ($input['numberOfWorkers'] ?? max(1, $peak));
        $maximumAllowedWorkers = min(6, (int) config('cleaning_booking_v2.max_workers'));
        if ($requestedWorkers < 1 || $requestedWorkers > $maximumAllowedWorkers) {
            $this->invalid('numberOfWorkers', "اختر عدداً من العمال بين 1 و{$maximumAllowedWorkers}.");
        }
        if ($peak > $requestedWorkers) {
            $this->invalid('numberOfWorkers', 'المواعيد المتزامنة تحتاج فريقاً أكبر. عدّل وقت الغرف أو زد عدد العمال.');
        }
        app(CleaningTimePeriodWorkerPolicy::class)->validate($input, $allSchedules, $peak);
        $preferred = array_unique(array_filter(array_map('intval', [...(array) ($input['preferredWorkerIds'] ?? []), $input['preferredWorkerId'] ?? 0])));
        if (count($preferred) > $requestedWorkers) {
            $this->invalid('preferredWorkerIds', 'عدد العمال المفضلين أكبر من حجم الفريق المطلوب.');
        }
        $materialsTotal = round(array_sum(array_column($kits, 'totalPrice')), 2);
        $addons = round($materialsTotal + $specialTotal, 2);
        $supervisor = null;
        if ($requestedWorkers > 1) {
            $supervisor = app(CleaningSupervisorHourlyPricing::class)->forMinutes(
                app(CleaningSupervisorHourlyPricing::class)->unionMinutes($allSchedules),
            );
            $base = $this->calculator->roundMoney($base + $supervisor['fee']);
        }
        // Transport allowance belongs to each booked worker seat, even when
        // the room schedule's peak concurrency is lower than the chosen team.
        $financial = CleaningRuntimeSettings::financial();
        if ((float) $financial->travel_markup_value <= 0) {
            $this->invalid('transport', 'يجب إعداد بدل مواصلات ثابت للعامل قبل تسعير الطلب.');
        }
        $perWorkerTravel = $this->calculator->roundMoney((float) $financial->travel_markup_value);
        $priced = $this->calculator->provisional($base, $addons, 0, $requestedWorkers * count($appointments));
        $minimum = max(0.0, (float) (CleaningRuntimeSettings::financial()->cleaning_minimum_order_price ?? 0));
        if ($priced['totalPrice'] < $minimum) {
            $base = $this->calculator->roundMoney(max($base, $minimum - $priced['travelFee'] - $addons));
            $priced = $this->calculator->provisional($base, $addons, $base + $addons, $requestedWorkers * count($appointments));
        }
        $this->allocateAppointmentPricing($appointments, $base, $kits, $priced, (float) ($supervisor['fee'] ?? 0));

        return ['algorithmVersion' => self::ALGORITHM, 'timezone' => $timezone, 'rooms' => $input['rooms'], 'appointments' => $appointments, 'requestedWorkerCount' => $requestedWorkers, 'requiredSimultaneousWorkers' => $peak, 'reconciliationRequired' => $reconciliation, 'basePrice' => $base, 'supervisor' => $supervisor, 'addonsTotal' => $addons, 'materialsTotal' => $materialsTotal, 'specialServicesTotal' => $specialTotal, 'materialKits' => array_values($kits), 'travelFee' => (float) $priced['travelFee'], 'transportAllowancePerWorker' => $perWorkerTravel, 'adminMargin' => (float) $priced['adminMargin'], 'totalPrice' => (float) $priced['totalPrice'], 'currency' => (string) config('app.currency', 'SYP'), 'estimatedWorkMinutes' => array_sum(array_column($allSchedules, 'estimatedWorkMinutes'))];
    }

    private function allocateAppointmentPricing(array &$appointments, float $base, array $kits, array $priced, float $supervisorFee): void
    {
        $rawBase = 0.0;
        foreach ($appointments as &$appointment) {
            $appointmentBase = array_sum(array_column($appointment['roomSchedules'], 'basePrice'));
            $material = array_sum(array_column(array_filter($kits, static fn (string $key): bool => str_starts_with($key, $appointment['appointmentKey'].':'), ARRAY_FILTER_USE_KEY), 'totalPrice'));
            $special = 0.0;
            foreach ($appointment['timePeriods'] as $period) {
                foreach ($period['serviceLines'] as $service) {
                    $special += $service['quoteLine']['totalPrice'];
                }
            }
            $appointment['financial'] = ['basePrice' => $appointmentBase, 'materialsTotal' => $material, 'specialServicesTotal' => $special, 'addonsTotal' => $material + $special];
            $first = collect($appointment['roomSchedules'])->min('startAt');
            $last = collect($appointment['roomSchedules'])->max('endAt');
            $appointment['estimatedDurationMinutes'] = (int) CarbonImmutable::parse($first)->diffInMinutes(CarbonImmutable::parse($last));
            $rawBase += $appointmentBase;
        }
        unset($appointment);
        // Allocate the supervisor fee to each independent order, in proportion
        // to its booked working minutes. The sum remains the quoted fee.
        $remainingSupervisorCents = (int) round($supervisorFee * 100);
        $durationWeight = array_sum(array_map(static fn (array $appointment): int => array_sum(array_column($appointment['roomSchedules'], 'estimatedWorkMinutes')), $appointments));
        foreach ($appointments as $index => &$appointment) {
            $minutes = array_sum(array_column($appointment['roomSchedules'], 'estimatedWorkMinutes'));
            $last = $index === array_key_last($appointments);
            $cents = $last ? $remainingSupervisorCents : (int) round($supervisorFee * 100 * ($durationWeight > 0 ? $minutes / $durationWeight : 1 / count($appointments)));
            $appointment['financial']['supervisorFee'] = $cents / 100;
            $appointment['financial']['basePrice'] = round($appointment['financial']['basePrice'] + $cents / 100, 2);
            $remainingSupervisorCents -= $cents;
        }
        unset($appointment);
        $appointments[0]['financial']['basePrice'] = round($appointments[0]['financial']['basePrice'] + $base - $rawBase - $supervisorFee, 2);
        $weight = array_sum(array_map(static fn (array $appointment): float => $appointment['financial']['basePrice'] + $appointment['financial']['addonsTotal'], $appointments));
        $remainingTravel = (int) round($priced['travelFee'] * 100);
        $remainingAdmin = (int) round($priced['adminMargin'] * 100);
        $included = (float) ($priced['includedAdminMargin'] ?? 0);
        $remainingIncluded = (int) round($included * 100);
        $rawTotal = $base + array_sum(array_column(array_column($appointments, 'financial'), 'addonsTotal')) + $priced['travelFee'];
        $rounding = round($priced['totalPrice'] - $rawTotal, 2);
        foreach ($appointments as $index => &$appointment) {
            $share = $weight > 0 ? ($appointment['financial']['basePrice'] + $appointment['financial']['addonsTotal']) / $weight : 1 / count($appointments);
            $last = $index === array_key_last($appointments);
            $travel = $last ? $remainingTravel : intdiv((int) round($priced['travelFee'] * 100), count($appointments));
            $admin = $last ? $remainingAdmin : (int) round($priced['adminMargin'] * 100 * $share);
            $includedShare = $last ? $remainingIncluded : (int) round($included * 100 * $share);
            $appointmentRounding = $last ? $rounding : 0.0;
            $total = round($appointment['financial']['basePrice'] + $appointment['financial']['addonsTotal'] + $travel / 100 + $appointmentRounding, 2);
            $appointment['financial'] += ['travelFee' => $travel / 100, 'adminMargin' => $admin / 100, 'includedAdminMargin' => $includedShare / 100, 'cashRoundingAdjustment' => $appointmentRounding, 'totalPrice' => $total];
            $remainingTravel -= $travel;
            $remainingAdmin -= $admin;
            $remainingIncluded -= $includedShare;
        }
        unset($appointment);
    }

    private function periodMaterials(array $group, array $schedules, array $rooms, string $appointmentKey, array &$kits): array
    {
        $choice = $group['materials']['choice'];
        $selected = array_unique(array_map('intval', $group['materials']['materialTypeIds']));
        if (($choice === 'customer_all' || $choice === 'platform_all') && $selected !== []) {
            $this->invalid('appointments', 'قائمة المواد الناقصة تخص خيار بعض المواد ناقصة فقط.');
        }
        if ($choice === 'customer_partial' && $selected === []) {
            $this->invalid('appointments', 'حدد المواد الناقصة.');
        }
        if ($choice === 'customer_all') {
            return [];
        }
        $lines = [];
        foreach ($schedules as $schedule) {
            if (! in_array($schedule['scheduleKey'], $group['roomScheduleKeys'], true)) {
                continue;
            }
            $room = $rooms[$schedule['roomKey']];
            $quote = $this->materials->quote(['cleaning_mode' => $room['cleaningMode'], 'room_size_breakdown' => [$room['roomType'] => [$room['size'] => 1]]], $choice === 'platform_all' ? null : $selected, false, false);
            foreach ($quote['lines'] as $line) {
                $id = $line['materialTypeId'];
                if (! isset($lines[$id])) {
                    $lines[$id] = $line;
                } else {
                    $lines[$id]['quantity'] += $line['quantity'];
                    $lines[$id]['totalPrice'] += $line['totalPrice'];
                }
            }
        }
        if ($choice === 'customer_partial' && array_diff($selected, array_keys($lines)) !== []) {
            $this->invalid('appointments', 'المادة المختارة غير متوافقة مع غرف الفترة.');
        }
        foreach ($lines as $id => $line) {
            if (in_array(mb_strtolower((string) $line['unit']), ['pack', 'package', 'bottle', 'piece'], true)) {
                $line['quantity'] = (float) ceil($line['quantity']);
            }
            $line['totalPrice'] = round($line['quantity'] * $line['unitPrice'], 2);
            $lines[$id] = $line;
            $key = $appointmentKey.':'.$id;
            if (! isset($kits[$key]) || $kits[$key]['quantity'] < $line['quantity']) {
                $kits[$key] = $line;
            }
        }

        return array_values($lines);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
