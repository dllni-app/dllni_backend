<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;

final class CleaningTimePeriodCustomerDetailService
{
    public function forBooking(CleaningBooking $booking): ?array
    {
        $schedules = $booking->roomSchedules()->with('room')->orderBy('starts_at')->get();
        if ($schedules->isEmpty()) {
            return null;
        }
        $rooms = $booking->rooms()->whereNotNull('client_key')->orderBy('id')->get()
            ->map(static fn ($room): array => [
                'roomKey' => (string) $room->client_key,
                'roomType' => (string) $room->room_type,
                'size' => (string) $room->room_size,
                'cleaningMode' => (string) $room->cleaning_mode,
            ])->values()->all();
        $periods = $booking->timePeriods()->orderBy('starts_at')->get();
        $ids = $periods->pluck('id')->all();
        $members = DB::table('cleaning_time_period_room_schedules')->whereIn('cleaning_time_period_id', $ids)->get()->groupBy('cleaning_time_period_id');
        $materials = DB::table('cleaning_time_period_materials')->whereIn('cleaning_time_period_id', $ids)->orderBy('cleaning_material_type_id')->get()->groupBy('cleaning_time_period_id');
        $services = DB::table('cleaning_time_period_services')->whereIn('cleaning_time_period_id', $ids)->orderBy('id')->get()->groupBy('cleaning_time_period_id');
        $byId = $schedules->keyBy('id');
        $visits = [];
        foreach ($booking->sessions()->orderBy('sequence')->get() as $session) {
            $sessionSchedules = $schedules->where('cleaning_booking_session_id', $session->id);
            if ($sessionSchedules->isEmpty()) {
                continue;
            }
            $tz = (string) $sessionSchedules->first()->timezone;
            $date = static fn ($value): string => $value->setTimezone($tz)->toIso8601String();
            $first = $sessionSchedules->sortBy('starts_at')->first();
            $last = $sessionSchedules->sortByDesc('ends_at')->first();
            $scheduleItems = $sessionSchedules->map(static fn ($row): array => [
                'scheduleKey' => (string) $row->schedule_key,
                'roomKey' => (string) $row->room?->client_key,
                'scheduledDate' => $row->starts_at->setTimezone($row->timezone)->toDateString(),
                'scheduledTime' => $row->starts_at->setTimezone($row->timezone)->format('H:i'),
                'numberOfWorkers' => (int) $row->required_workers,
                'estimatedWorkMinutes' => (int) $row->work_minutes,
                'estimatedDurationMinutes' => (int) $row->duration_minutes,
                'startAt' => $date($row->starts_at),
                'endAt' => $date($row->ends_at),
            ])->values()->all();
            $periodItems = [];
            foreach ($periods->where('cleaning_booking_session_id', $session->id) as $period) {
                $roomScheduleKeys = ($members->get($period->id) ?? collect())
                    ->map(static fn ($pivot): ?string => $byId->get($pivot->cleaning_room_schedule_id)?->schedule_key)
                    ->filter()->sort()->values()->all();
                $materialRows = $materials->get($period->id) ?? collect();
                $platformMaterials = $materialRows->map(static function ($row): array {
                    $snapshot = json_decode((string) $row->snapshot, true) ?: [];

                    return [
                        'materialTypeId' => (int) $row->cleaning_material_type_id,
                        'name' => (string) ($snapshot['name'] ?? ''),
                        'quantity' => (float) $row->quantity,
                        'unit' => (string) $row->unit,
                    ];
                })->values()->all();
                $servicesByKey = [];
                foreach ($services->get($period->id) ?? collect() as $service) {
                    $snapshot = json_decode((string) $service->snapshot, true) ?: [];
                    $key = (string) $service->service_key;
                    if (! isset($servicesByKey[$key])) {
                        $servicesByKey[$key] = [
                            'serviceKey' => $key,
                            'specialServiceId' => (int) $service->cleaning_special_service_id,
                            'name' => (string) ($snapshot['quoteLine']['name'] ?? ''),
                            'targetRoomKeys' => [],
                            'quantity' => (float) $service->quantity,
                            'unit' => (string) $service->unit,
                            'dirtinessLevel' => $service->dirtiness_level,
                        ];
                    }
                    $target = $byId->get($service->cleaning_room_schedule_id)?->room?->client_key;
                    if ($target !== null) {
                        $servicesByKey[$key]['targetRoomKeys'][] = (string) $target;
                    }
                }
                foreach ($servicesByKey as &$service) {
                    sort($service['targetRoomKeys'], SORT_STRING);
                }
                unset($service);
                $periodItems[] = [
                    'periodKey' => (string) $period->period_key,
                    'roomScheduleKeys' => $roomScheduleKeys,
                    'startAt' => $date($period->starts_at),
                    'endAt' => $date($period->ends_at),
                    'materials' => [
                        'choice' => (string) $period->materials_choice,
                        'materialTypeIds' => $period->materials_choice === 'customer_partial'
                            ? $materialRows->pluck('cleaning_material_type_id')->map(static fn ($id): int => (int) $id)->all()
                            : [],
                    ],
                    'platformMaterials' => $platformMaterials,
                    'specialServices' => array_values($servicesByKey),
                ];
            }
            $visits[] = [
                'visitKey' => (string) $first->visit_key,
                'sequence' => (int) $session->sequence,
                'startAt' => $date($first->starts_at),
                'endAt' => $date($last->ends_at),
                'timezone' => $tz,
                'estimatedDurationMinutes' => (int) round($first->starts_at->diffInSeconds($last->ends_at) / 60),
                'requiredSimultaneousWorkers' => (int) $session->required_workers,
                'roomSchedules' => $scheduleItems,
                'timePeriods' => $periodItems,
            ];
        }
        $dates = array_map(static fn (array $visit): string => mb_substr($visit['startAt'], 0, 10), $visits);
        $acceptedTravel = (float) $booking->workerAssignments()
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->sum('travel_fee');
        // Historical signed V2 quotes predate travelFeePending. Keep them
        // transparent too when accepted workers' transport exceeds the amount
        // actually included in the customer booking price.
        $quotedTravel = (float) data_get($booking->property_details, '_time_period_v2.pricing.travelFee', 0);
        $quotedTotal = (float) data_get($booking->property_details, '_time_period_v2.pricing.totalPrice', $booking->total_price);
        $customerIncreaseSinceQuote = max(0.0, (float) $booking->total_price - $quotedTotal);
        $unfundedTransport = max(0.0,
            max((float) $booking->travel_fee, $acceptedTravel) - $quotedTravel - $customerIncreaseSinceQuote,
        );
        $travelFeePending = (bool) data_get($booking->property_details, '_time_period_v2.pricing.travelFeePending', false)
            || round($unfundedTransport, 2) > 0;

        return [
            'rooms' => $rooms,
            'visits' => $visits,
            'recurrence' => [
                'isRecurring' => count($visits) > 1,
                'visitCount' => count($visits),
                'cadence' => $this->cadence($dates),
            ],
            'workforce' => ['requestedWorkers' => (int) $booking->number_of_workers],
            'pricing' => [
                'totalPrice' => (float) $booking->total_price,
                'currency' => (string) config('app.currency', 'SYP'),
                // A distance-dependent transport allowance is not known when
                // the customer signs the service quote. Preserve that status
                // after creation instead of presenting the subtotal as final.
                'isPricingFinal' => ! $travelFeePending,
                'travelFeePending' => $travelFeePending,
                'coverage' => 'all_visits',
            ],
        ];
    }

    private function cadence(array $dates): string
    {
        if (count($dates) < 2) {
            return 'once';
        }
        $start = CarbonImmutable::parse($dates[0]);
        $weekly = $monthly = true;
        foreach ($dates as $index => $date) {
            $weekly = $weekly && $start->addWeeks($index)->toDateString() === $date;
            $monthly = $monthly && $start->addMonthsNoOverflow($index)->toDateString() === $date
                && (int) mb_substr($date, 8, 2) === $start->day;
        }

        return $weekly ? 'weekly' : ($monthly ? 'monthly' : 'custom');
    }
}
