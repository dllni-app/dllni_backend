from pathlib import Path
import re


def read(path: str) -> str:
    return Path(path).read_text()


def write(path: str, text: str) -> None:
    Path(path).write_text(text)


def replace_once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if count != 1:
        raise RuntimeError(f"{label}: expected 1 match, found {count}")
    return text.replace(old, new, 1)


# 1) Validation contract: recurring calculationMode=task|hours and hoursPerVisit.
path = 'Modules/User/app/Http/Requests/Concerns/ValidatesEventAssistanceSchedule.php'
text = read(path)
text = replace_once(
    text,
    "            'schedule' => ['sometimes', 'array:mode,sessions'],\n",
    "            'schedule' => ['sometimes', 'array:mode,sessions,calculationMode,hoursPerVisit'],\n",
    'schedule allowed keys',
)
text = replace_once(
    text,
    "            'schedule.mode' => ['required_with:schedule', 'string', Rule::in($scheduleModes)],\n",
    "            'schedule.mode' => ['required_with:schedule', 'string', Rule::in($scheduleModes)],\n"
    "            'schedule.calculationMode' => $isEventAssistance\n"
    "                ? ['prohibited']\n"
    "                : ['sometimes', 'string', Rule::in(['task', 'hours'])],\n"
    "            'schedule.hoursPerVisit' => $isEventAssistance\n"
    "                ? ['prohibited']\n"
    "                : ['sometimes', 'numeric', 'min:1', 'max:24'],\n",
    'calculation mode rules',
)
needle = "            if (count($sessions) < 2) {\n                $validator->errors()->add(\n                    'schedule.sessions',\n                    'الحجز الدوري يحتاج إلى زيارتين على الأقل.',\n                );\n            }\n\n"
replacement = needle + "            $calculationMode = mb_strtolower(mb_trim((string) ($schedule['calculationMode'] ?? 'task')));\n            $hasHoursPerVisit = array_key_exists('hoursPerVisit', $schedule);\n            if ($calculationMode === 'hours' && (! $hasHoursPerVisit || ! is_numeric($schedule['hoursPerVisit']))) {\n                $validator->errors()->add(\n                    'schedule.hoursPerVisit',\n                    'حدد عدد الساعات لكل زيارة عند اختيار الحجز الدوري بالساعة.',\n                );\n            }\n            if ($calculationMode !== 'hours' && $hasHoursPerVisit) {\n                $validator->errors()->add(\n                    'schedule.hoursPerVisit',\n                    'عدد الساعات لكل زيارة مسموح فقط عند اختيار الحجز الدوري بالساعة.',\n                );\n            }\n\n"
text = replace_once(text, needle, replacement, 'recurring mode validation')
write(path, text)


# 2) Recurring plan/materialization owns the canonical mode and per-visit duration.
path = 'Modules/User/app/Services/RecurringCleaningScheduleService.php'
text = read(path)
text = replace_once(
    text,
    "final class RecurringCleaningScheduleService\n{\n    public const SESSION_TYPE = 'recurring_cleaning';\n",
    "final class RecurringCleaningScheduleService\n{\n    public const SESSION_TYPE = 'recurring_cleaning';\n\n"
    "    public const CALCULATION_TASK = 'task';\n\n"
    "    public const CALCULATION_HOURS = 'hours';\n",
    'recurring calculation constants',
)
text = replace_once(
    text,
    "        if (mb_strtolower(mb_trim((string) ($schedule['mode'] ?? ''))) !== 'recurring') {\n            return null;\n        }\n\n        $sessions = [];\n",
    "        if (mb_strtolower(mb_trim((string) ($schedule['mode'] ?? ''))) !== 'recurring') {\n            return null;\n        }\n\n"
    "        $calculationMode = mb_strtolower(mb_trim((string) ($schedule['calculationMode'] ?? self::CALCULATION_TASK)));\n"
    "        if (! in_array($calculationMode, [self::CALCULATION_TASK, self::CALCULATION_HOURS], true)) {\n"
    "            return null;\n"
    "        }\n"
    "        $hoursPerVisit = $calculationMode === self::CALCULATION_HOURS\n"
    "            ? $this->normalizeHours((float) ($schedule['hoursPerVisit'] ?? 0))\n"
    "            : null;\n"
    "        if ($calculationMode === self::CALCULATION_HOURS && ($hoursPerVisit === null || $hoursPerVisit < 1.0)) {\n"
    "            return null;\n"
    "        }\n\n"
    "        $sessions = [];\n",
    'resolve calculation mode',
)
text = replace_once(
    text,
    "            'sessionsCount' => count($normalized),\n            'firstDate' => $normalized[0]['date'],\n",
    "            'sessionsCount' => count($normalized),\n"
    "            'calculationMode' => $calculationMode,\n"
    "            'hoursPerVisit' => $hoursPerVisit,\n"
    "            'firstDate' => $normalized[0]['date'],\n",
    'resolved plan mode fields',
)
text = replace_once(
    text,
    "                'isMultiSession' => true,\n                'sessionsCount' => $count,\n",
    "                'isMultiSession' => true,\n"
    "                'calculationMode' => (string) ($plan['calculationMode'] ?? self::CALCULATION_TASK),\n"
    "                'hoursPerVisit' => isset($plan['hoursPerVisit']) ? (float) $plan['hoursPerVisit'] : null,\n"
    "                'sessionsCount' => $count,\n",
    'quote schedule mode fields',
)
text = replace_once(
    text,
    "        $count = max(1, (int) $plan['sessionsCount']);\n        $sessionHours = max(0.0, (float) $booking->estimated_hours);\n",
    "        $count = max(1, (int) $plan['sessionsCount']);\n"
    "        $calculationMode = (string) ($plan['calculationMode'] ?? self::CALCULATION_TASK);\n"
    "        $sessionHours = $calculationMode === self::CALCULATION_HOURS\n"
    "            ? max(1.0, (float) ($plan['hoursPerVisit'] ?? $booking->estimated_hours))\n"
    "            : max(0.0, (float) $booking->estimated_hours);\n",
    'materialize session hours',
)
text = replace_once(
    text,
    "                'calculation_mode' => 'estimated_hours',\n",
    "                'calculation_mode' => $calculationMode,\n",
    'session calculation mode',
)
text = replace_once(
    text,
    "                    'occurrencesCount' => $count,\n                    'perVisitEstimatedHours' => round($sessionHours, 2),\n",
    "                    'occurrencesCount' => $count,\n"
    "                    'calculationMode' => $calculationMode,\n"
    "                    'hoursPerVisit' => $calculationMode === self::CALCULATION_HOURS ? round($sessionHours, 2) : null,\n"
    "                    'perVisitEstimatedHours' => round($sessionHours, 2),\n",
    'pricing snapshot calculation mode',
)
# Add a tiny normalizer at the end.
text = replace_once(
    text,
    "        return $booking->fresh() ?? $booking;\n    }\n}\n",
    "        return $booking->fresh() ?? $booking;\n    }\n\n"
    "    private function normalizeHours(float $hours): ?float\n"
    "    {\n"
    "        if ($hours < 1.0 || $hours > 24.0) {\n"
    "            return null;\n"
    "        }\n\n"
    "        return ceil($hours * 2) / 2;\n"
    "    }\n"
    "}\n",
    'hour normalizer',
)
write(path, text)


# 3) Add canonical hour-based pricing to the normal cleaning estimator.
path = 'Modules/User/app/Services/UserCleaningOrderEstimationService.php'
text = read(path)
marker = "    private function calculateRegularCleaningFromSettings(array $normalizedDetails): array\n"
if text.count(marker) != 1:
    raise RuntimeError('estimation method insertion marker')
method = r'''    /**
     * Price a recurring cleaning visit by booked worker-hours while deriving the
     * hourly labor rate from the same room/task pricing configuration used by
     * task-based cleaning. This keeps one financial source of truth.
     *
     * @return array<string, mixed>
     */
    public function priceRecurringHours(
        string $propertyType,
        array $propertyDetails,
        mixed $addressLatitude,
        mixed $addressLongitude,
        mixed $preferredWorkerId,
        float $hoursPerVisit,
        int $workerCount,
    ): array {
        $input = $this->pricingSnapshotInput(
            $propertyType,
            $propertyDetails,
            $addressLatitude,
            $addressLongitude,
            $preferredWorkerId,
        );
        if ($this->isEventAssistanceType($input['propertyType'])) {
            throw new InvalidArgumentException('Recurring hour-based pricing is only available for normal cleaning.');
        }

        $regularCalculation = $this->calculateRegularCleaningFromSettings($input['propertyDetails']);
        $taskBasePrice = $this->pricingCalculator->roundMoney((float) $regularCalculation['basePrice']);
        $taskEstimatedHours = max(0.5, (float) $regularCalculation['estimatedHours']);
        $normalizedHours = min(24.0, max(1.0, $this->roundToHalfHour($hoursPerVisit)));
        $normalizedWorkers = max(1, $workerCount);
        $hourlyRatePerWorker = $this->pricingCalculator->roundMoney($taskBasePrice / $taskEstimatedHours);
        $basePrice = $this->pricingCalculator->roundMoney(
            $hourlyRatePerWorker * $normalizedHours * $normalizedWorkers,
        );
        $addonsTotal = 0.0;

        if ($input['preferredWorkerId'] === null) {
            $pricing = $this->pricingCalculator->provisional($basePrice, $addonsTotal);
        } else {
            $worker = Worker::query()->find($input['preferredWorkerId']);
            if (! $worker) {
                throw new InvalidArgumentException('Selected worker is not available.');
            }

            $pricing = $this->pricingCalculator->finalizedForWorker(
                $basePrice,
                $addonsTotal,
                $input['addressLatitude'],
                $input['addressLongitude'],
                $worker,
            );
        }

        return [
            'basePrice' => $basePrice,
            'addonsTotal' => $addonsTotal,
            'travelFee' => $pricing['travelFee'],
            'distanceKm' => $pricing['distanceKm'],
            'adminMargin' => $pricing['adminMargin'],
            'isPricingFinal' => $pricing['isPricingFinal'],
            'totalPrice' => $pricing['totalPrice'],
            'currency' => (string) config('app.currency', 'SYP'),
            'serviceLines' => [],
            'roomPricingLines' => $regularCalculation['roomPricingLines'] ?? [],
            'pricingAlgorithm' => [
                'mode' => 'hours',
                'derivedHourlyRatePerWorker' => $hourlyRatePerWorker,
                'derivedFromTaskBasePrice' => $taskBasePrice,
                'derivedFromTaskEstimatedHours' => round($taskEstimatedHours, 2),
                'bookedHoursPerVisit' => round($normalizedHours, 2),
                'workerCount' => $normalizedWorkers,
            ],
            'recurringHourlyRatePerWorker' => $hourlyRatePerWorker,
            'recurringHoursPerVisit' => round($normalizedHours, 2),
            'recurringWorkerCount' => $normalizedWorkers,
            'eventHourlyRate' => null,
            'eventHours' => null,
            'eventWorkerCount' => null,
            'recommendation' => null,
        ];
    }

'''
text = text.replace(marker, method + marker, 1)
write(path, text)


# 4) Estimate-price controller: duration/capacity/pricing follow selected recurring mode.
path = 'Modules/User/app/Http/Controllers/API/UserCleaningOrderEstimatePriceController.php'
text = read(path)
text = replace_once(
    text,
    "            $singleVisitEstimatedHours = (float) $estimation['estimatedHours'];\n\n            if ($eventPlan !== null) {\n",
    "            $singleVisitEstimatedHours = (float) $estimation['estimatedHours'];\n"
    "            $recurringSessionHours = $recurringPlan !== null\n"
    "                && (string) ($recurringPlan['calculationMode'] ?? RecurringCleaningScheduleService::CALCULATION_TASK) === RecurringCleaningScheduleService::CALCULATION_HOURS\n"
    "                    ? (float) ($recurringPlan['hoursPerVisit'] ?? $singleVisitEstimatedHours)\n"
    "                    : $singleVisitEstimatedHours;\n\n"
    "            if ($eventPlan !== null) {\n",
    'estimate recurring session hours',
)
text = replace_once(
    text,
    "                    $singleVisitEstimatedHours * (int) $recurringPlan['sessionsCount'],\n",
    "                    $recurringSessionHours * (int) $recurringPlan['sessionsCount'],\n",
    'aggregate recurring hours',
)
text = replace_once(
    text,
    "                : ($recurringPlan !== null\n                    ? $singleVisitEstimatedHours\n                    : (float) $estimation['estimatedHours']);\n",
    "                : ($recurringPlan !== null\n                    ? $recurringSessionHours\n                    : (float) $estimation['estimatedHours']);\n",
    'capacity recurring hours',
)
old = """            } else {
                $pricing = $service->price(
                    (string) $validated['propertyType'],
                    $pricingPropertyDetails,
                    $addressLatitude,
                    $addressLongitude,
                    $assignmentMode === 'preferred_worker' ? ($validated['preferredWorkerId'] ?? null) : null,
                    isset($validated['serviceIds']) ? (array) $validated['serviceIds'] : null,
                );

                if ($recurringPlan !== null) {
                    $pricing = $recurringSchedule->quote(
                        $recurringPlan,
                        $pricing,
                        $singleVisitEstimatedHours,
                    );
                }
            }
"""
new = """            } else {
                $pricing = $recurringPlan !== null
                    && (string) ($recurringPlan['calculationMode'] ?? RecurringCleaningScheduleService::CALCULATION_TASK) === RecurringCleaningScheduleService::CALCULATION_HOURS
                        ? $service->priceRecurringHours(
                            (string) $validated['propertyType'],
                            $pricingPropertyDetails,
                            $addressLatitude,
                            $addressLongitude,
                            $assignmentMode === 'preferred_worker' ? ($validated['preferredWorkerId'] ?? null) : null,
                            $recurringSessionHours,
                            $requestedWorkers,
                        )
                        : $service->price(
                            (string) $validated['propertyType'],
                            $pricingPropertyDetails,
                            $addressLatitude,
                            $addressLongitude,
                            $assignmentMode === 'preferred_worker' ? ($validated['preferredWorkerId'] ?? null) : null,
                            isset($validated['serviceIds']) ? (array) $validated['serviceIds'] : null,
                        );

                if ($recurringPlan !== null) {
                    $pricing = $recurringSchedule->quote(
                        $recurringPlan,
                        $pricing,
                        $recurringSessionHours,
                    );
                }
            }
"""
text = replace_once(text, old, new, 'estimate recurring pricing branch')
write(path, text)


# 5) Store controller: capacity is based on the per-visit hour mode and materialization starts from its canonical single-visit quote.
path = 'Modules/User/app/Http/Controllers/API/UserCleaningOrderStoreController.php'
text = read(path)
text = replace_once(
    text,
    "            $service,\n            $eventSchedule,\n",
    "            $service,\n            $estimationService,\n            $eventSchedule,\n",
    'store closure estimator capture',
)
old = """            } elseif ($recurringPlan !== null) {
                $order = $recurringSchedule->materialize($order, $recurringPlan);
            }
"""
new = """            } elseif ($recurringPlan !== null) {
                if ((string) ($recurringPlan['calculationMode'] ?? RecurringCleaningScheduleService::CALCULATION_TASK) === RecurringCleaningScheduleService::CALCULATION_HOURS) {
                    $sessionHours = (float) ($recurringPlan['hoursPerVisit'] ?? $order->estimated_hours);
                    $hourPricing = $estimationService->priceRecurringHours(
                        (string) $order->property_type,
                        (array) ($order->property_details ?? []),
                        $order->address_latitude,
                        $order->address_longitude,
                        $order->resolvedAssignmentMode() === 'preferred_worker' ? $order->preferred_worker_id : null,
                        $sessionHours,
                        max(1, (int) $order->number_of_workers),
                    );
                    $storedHourPricing = $order->resolvedAssignmentMode() === 'preferred_worker'
                        ? $hourPricing
                        : [
                            ...$hourPricing,
                            'travelFee' => 0.0,
                            'distanceKm' => null,
                            'adminMargin' => 0.0,
                            'isPricingFinal' => false,
                            'totalPrice' => round((float) $hourPricing['basePrice'] + (float) $hourPricing['addonsTotal'], 2),
                        ];
                    $order->forceFill([
                        'estimated_hours' => $sessionHours,
                        'total_hours' => $sessionHours,
                        'base_price' => $storedHourPricing['basePrice'],
                        'addons_total' => $storedHourPricing['addonsTotal'],
                        'travel_fee' => $storedHourPricing['travelFee'],
                        'travel_distance_km' => $storedHourPricing['distanceKm'],
                        'admin_margin_amount' => $storedHourPricing['adminMargin'],
                        'is_pricing_final' => $storedHourPricing['isPricingFinal'],
                        'total_price' => $storedHourPricing['totalPrice'],
                    ])->save();
                    $order = $order->fresh();
                }

                $order = $recurringSchedule->materialize($order, $recurringPlan);
            }
"""
text = replace_once(text, old, new, 'store recurring hour materialization')
old = """        $estimation = $estimationService->estimate(
            $propertyType,
            (array) ($validated['propertyDetails'] ?? []),
        );
        $requiredWorkers = CleaningWorkerCapacity::requiredWorkers((float) $estimation['estimatedHours']);
"""
new = """        $estimation = $estimationService->estimate(
            $propertyType,
            (array) ($validated['propertyDetails'] ?? []),
        );
        $schedule = is_array($validated['schedule'] ?? null) ? $validated['schedule'] : [];
        $calculationMode = mb_strtolower(mb_trim((string) ($schedule['calculationMode'] ?? RecurringCleaningScheduleService::CALCULATION_TASK)));
        $capacityHours = mb_strtolower(mb_trim((string) ($schedule['mode'] ?? ''))) === 'recurring'
            && $calculationMode === RecurringCleaningScheduleService::CALCULATION_HOURS
                ? ceil(max(1.0, min(24.0, (float) ($schedule['hoursPerVisit'] ?? 0))) * 2) / 2
                : (float) $estimation['estimatedHours'];
        $requiredWorkers = CleaningWorkerCapacity::requiredWorkers($capacityHours);
"""
text = replace_once(text, old, new, 'store worker capacity hours')
write(path, text)


# 6) Revision preserves the original calculation mode and hour duration.
path = 'Modules/User/app/Services/RecurringCleaningScheduleRevisionService.php'
text = read(path)
text = replace_once(
    text,
    "                    'calculation_mode' => 'estimated_hours',\n",
    "                    'calculation_mode' => $built['calculationMode'],\n",
    'revision calculation mode',
)
text = replace_once(
    text,
    "                        'pricingAlgorithmVersion' => $this->estimationService->algorithmVersion(),\n                        'perVisitEstimatedHours' => $built['sessionHours'],\n",
    "                        'pricingAlgorithmVersion' => $this->estimationService->algorithmVersion(),\n"
    "                        'calculationMode' => $built['calculationMode'],\n"
    "                        'hoursPerVisit' => $built['calculationMode'] === RecurringCleaningScheduleService::CALCULATION_HOURS ? $built['sessionHours'] : null,\n"
    "                        'perVisitEstimatedHours' => $built['sessionHours'],\n"
    "                        'derivedHourlyRatePerWorker' => $built['singleVisitPricing']['recurringHourlyRatePerWorker'] ?? null,\n",
    'revision pricing snapshot mode',
)
old = """        $preferredWorkerId = $booking->resolvedAssignmentMode() === 'preferred_worker'
            ? $booking->preferred_worker_id
            : null;
        try {
            $singleVisitPricing = $this->estimationService->price(
                (string) $booking->property_type,
                (array) ($booking->property_details ?? []),
                $booking->address_latitude,
                $booking->address_longitude,
                $preferredWorkerId,
            );
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages([
                'schedule' => ['تعذر إعادة تسعير الزيارات المستقبلية. حدّث بيانات الحجز وحاول مرة أخرى.'],
            ]);
        }
        $estimate = $this->estimationService->estimate(
            (string) $booking->property_type,
            (array) ($booking->property_details ?? []),
        );
        $sessionHours = round(max(0.01, (float) ($estimate['estimatedHours'] ?? $editable->first()->duration_hours ?? 1)), 2);
"""
new = """        $preferredWorkerId = $booking->resolvedAssignmentMode() === 'preferred_worker'
            ? $booking->preferred_worker_id
            : null;
        $rawCalculationMode = mb_strtolower((string) ($editable->first()->calculation_mode ?? ''));
        $calculationMode = $rawCalculationMode === RecurringCleaningScheduleService::CALCULATION_HOURS
            ? RecurringCleaningScheduleService::CALCULATION_HOURS
            : RecurringCleaningScheduleService::CALCULATION_TASK;
        $estimate = $this->estimationService->estimate(
            (string) $booking->property_type,
            (array) ($booking->property_details ?? []),
        );
        $sessionHours = $calculationMode === RecurringCleaningScheduleService::CALCULATION_HOURS
            ? round(max(1.0, (float) ($editable->first()->duration_hours ?? 1)), 2)
            : round(max(0.01, (float) ($estimate['estimatedHours'] ?? $editable->first()->duration_hours ?? 1)), 2);
        try {
            $singleVisitPricing = $calculationMode === RecurringCleaningScheduleService::CALCULATION_HOURS
                ? $this->estimationService->priceRecurringHours(
                    (string) $booking->property_type,
                    (array) ($booking->property_details ?? []),
                    $booking->address_latitude,
                    $booking->address_longitude,
                    $preferredWorkerId,
                    $sessionHours,
                    max(1, (int) $booking->number_of_workers),
                )
                : $this->estimationService->price(
                    (string) $booking->property_type,
                    (array) ($booking->property_details ?? []),
                    $booking->address_latitude,
                    $booking->address_longitude,
                    $preferredWorkerId,
                );
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages([
                'schedule' => ['تعذر إعادة تسعير الزيارات المستقبلية. حدّث بيانات الحجز وحاول مرة أخرى.'],
            ]);
        }
"""
text = replace_once(text, old, new, 'revision pricing by mode')
text = replace_once(
    text,
    "            'proposedSessions' => $proposedSessions,\n            'newTotal' => $newTotal,\n",
    "            'proposedSessions' => $proposedSessions,\n"
    "            'calculationMode' => $calculationMode,\n"
    "            'sessionHours' => $sessionHours,\n"
    "            'newTotal' => $newTotal,\n",
    'revision token mode',
)
text = replace_once(
    text,
    "            'sessionHours' => $sessionHours,\n            'singleVisitPricing' => [\n",
    "            'sessionHours' => $sessionHours,\n"
    "            'calculationMode' => $calculationMode,\n"
    "            'hoursPerVisit' => $calculationMode === RecurringCleaningScheduleService::CALCULATION_HOURS ? $sessionHours : null,\n"
    "            'singleVisitPricing' => [\n",
    'revision preview mode',
)
text = replace_once(
    text,
    "            'sessionHours' => $sessionHours,\n        ];\n",
    "            'sessionHours' => $sessionHours,\n"
    "            'calculationMode' => $calculationMode,\n"
    "        ];\n",
    'revision build result mode',
)
write(path, text)


# 7) Existing recurring tests now assert explicit task mode, plus new hour-mode coverage.
path = 'tests/Feature/UserModule/RecurringCleaningScheduleTest.php'
text = read(path)
text = replace_once(
    text,
    "            'mode' => 'recurring',\n            // Intentionally unsorted.",
    "            'mode' => 'recurring',\n"
    "            'calculationMode' => 'task',\n"
    "            // Intentionally unsorted.",
    'test payload task mode',
)
text = text.replace("$session->calculation_mode === 'estimated_hours'", "$session->calculation_mode === RecurringCleaningScheduleService::CALCULATION_TASK")
anchor = "it('keeps legacy single cleaning unchanged when no recurring schedule is supplied', function (): void {\n"
if text.count(anchor) != 1:
    raise RuntimeError('hour test anchor')
hour_test = r'''it('supports fixed-hour recurring visits with worker-seat pricing', function (): void {
    $payload = recurringCleaningPayload();
    $payload['numberOfWorkers'] = 2;
    $payload['schedule']['calculationMode'] = 'hours';
    $payload['schedule']['hoursPerVisit'] = 2.25;

    $estimate = postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => $payload['propertyType'],
        'propertyDetails' => $payload['propertyDetails'],
        'addressLatitude' => $payload['addressLatitude'],
        'addressLongitude' => $payload['addressLongitude'],
        'assignmentMode' => $payload['assignmentMode'],
        'numberOfWorkers' => $payload['numberOfWorkers'],
        'schedule' => $payload['schedule'],
    ])->assertOk();

    $estimate
        ->assertJsonPath('schedule.calculationMode', 'hours')
        ->assertJsonPath('schedule.hoursPerVisit', 2.5)
        ->assertJsonPath('schedule.sessions.0.hours', 2.5)
        ->assertJsonPath('pricing.recurringHoursPerVisit', 2.5)
        ->assertJsonPath('pricing.recurringWorkerCount', 2);

    expect((float) $estimate->json('size.estimatedHours'))->toBe(7.5)
        ->and((float) $estimate->json('pricing.recurringHourlyRatePerWorker'))->toBeGreaterThan(0)
        ->and((float) $estimate->json('schedule.sessions.0.basePrice'))->toBeGreaterThan(0);

    $created = postJson('/api/v1/user/cleaning/orders', $payload)->assertCreated();
    $bookingId = (int) $created->json('order.id');
    $booking = CleaningBooking::query()->findOrFail($bookingId);
    $sessions = CleaningBookingSession::query()
        ->where('cleaning_booking_id', $bookingId)
        ->orderBy('sequence')
        ->get();

    expect($sessions)->toHaveCount(3)
        ->and($sessions->every(fn (CleaningBookingSession $session): bool => $session->calculation_mode === 'hours'))->toBeTrue()
        ->and($sessions->every(fn (CleaningBookingSession $session): bool => (float) $session->duration_hours === 2.5))->toBeTrue()
        ->and($sessions->every(fn (CleaningBookingSession $session): bool => (string) data_get($session->pricing_snapshot, 'calculationMode') === 'hours'))->toBeTrue()
        ->and((float) $booking->total_hours)->toBe(7.5);
});

it('validates the recurring calculation-mode contract', function (): void {
    $payload = recurringCleaningPayload();
    $payload['schedule']['calculationMode'] = 'hours';
    unset($payload['schedule']['hoursPerVisit']);

    postJson('/api/v1/user/cleaning/orders', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('schedule.hoursPerVisit');

    $payload = recurringCleaningPayload();
    $payload['schedule']['hoursPerVisit'] = 2;

    postJson('/api/v1/user/cleaning/orders', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('schedule.hoursPerVisit');

    $payload = recurringCleaningPayload();
    $payload['schedule']['calculationMode'] = 'hours';
    $payload['schedule']['hoursPerVisit'] = 25;

    postJson('/api/v1/user/cleaning/orders', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('schedule.hoursPerVisit');
});

'''
text = text.replace(anchor, hour_test + anchor, 1)
write(path, text)

# Add hour-mode preservation coverage to revision tests using a compact append.
path = 'tests/Feature/UserModule/RecurringCleaningScheduleRevisionTest.php'
text = read(path)
append = r'''

it('preserves hour-based pricing when future recurring visits are revised', function (): void {
    $payload = recurringRevisionPayload();
    $payload['schedule']['calculationMode'] = 'hours';
    $payload['schedule']['hoursPerVisit'] = 2.5;

    $created = postJson('/api/v1/user/cleaning/orders', $payload)->assertCreated();
    $bookingId = (int) $created->json('order.id');
    $schedule = [
        'mode' => 'recurring',
        'sessions' => [
            ['date' => now(config('app.timezone'))->addDays(3)->toDateString(), 'time' => '09:00'],
            ['date' => now(config('app.timezone'))->addDays(10)->toDateString(), 'time' => '09:00'],
        ],
    ];

    $preview = postJson("/api/v1/user/cleaning/orders/{$bookingId}/recurring-schedule/preview", [
        'schedule' => $schedule,
    ])->assertOk();

    $preview
        ->assertJsonPath('data.revision.calculationMode', 'hours')
        ->assertJsonPath('data.revision.hoursPerVisit', 2.5)
        ->assertJsonPath('data.revision.sessionHours', 2.5);

    $token = (string) $preview->json('data.revision.revisionToken');
    postJson("/api/v1/user/cleaning/orders/{$bookingId}/recurring-schedule/confirm", [
        'schedule' => $schedule,
        'revisionToken' => $token,
    ])->assertOk();

    $active = CleaningBookingSession::query()
        ->where('cleaning_booking_id', $bookingId)
        ->where('status', '!=', 'superseded')
        ->orderBy('sequence')
        ->get();

    expect($active)->toHaveCount(2)
        ->and($active->every(fn (CleaningBookingSession $session): bool => $session->calculation_mode === 'hours'))->toBeTrue()
        ->and($active->every(fn (CleaningBookingSession $session): bool => (float) $session->duration_hours === 2.5))->toBeTrue();
});
'''
if "preserves hour-based pricing when future recurring visits are revised" not in text:
    text = text.rstrip() + append + "\n"
write(path, text)
