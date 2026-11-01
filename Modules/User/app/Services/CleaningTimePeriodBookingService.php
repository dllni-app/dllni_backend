<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSpecialService;
use Modules\Cleaning\Models\CleaningBookingSpecialServiceItem;
use Modules\Cleaning\Models\CleaningRoomSchedule;
use Modules\Cleaning\Models\CleaningTimePeriod;
use Modules\Cleaning\Services\CleaningMaterialInventoryService;
use Modules\Cleaning\Services\CleaningSupervisorHourlyPricing;
use Modules\Cleaning\Services\CleaningPricingCalculator;
use Modules\Cleaning\Support\CleaningRuntimeSettings;

final class CleaningTimePeriodBookingService
{
    public function __construct(
        private readonly UserCleaningOrderService $legacy,
        private readonly CleaningTimePeriodPricingService $pricing,
        private readonly CleaningTimePeriodQuoteService $quotes,
        private readonly CleaningMaterialInventoryService $inventory,
    ) {}

    public function create(User $customer, array $input): CleaningBooking
    {
        try {
            return DB::transaction(function () use ($customer, $input): CleaningBooking {
                User::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
                $hash = $this->quotes->requestHash($input);
                $retry = DB::table('cleaning_booking_quotes')->where('customer_id', $customer->id)->where('idempotency_key', $input['idempotencyKey'])->first();
                if ($retry !== null) {
                    if (! hash_equals($retry->request_hash, $hash) || $retry->cleaning_booking_id === null) {
                        $this->invalid('idempotencyKey', 'تم استخدام محاولة التأكيد لتفاصيل مختلفة.');
                    }

                    return CleaningBooking::query()->findOrFail($retry->cleaning_booking_id);
                }
                $record = DB::table('cleaning_booking_quotes')->where('id', $input['quoteId'])->where('customer_id', $customer->id)->lockForUpdate()->first();
                if ($record === null || $record->cleaning_booking_id !== null || CarbonImmutable::parse($record->expires_at)->lessThanOrEqualTo(now())) {
                    $this->invalid('quoteId', 'انتهت صلاحية السعر. حدّث السعر قبل التأكيد.');
                }
                if (! hash_equals($record->signature, $input['quoteSignature']) || ! hash_equals($record->request_hash, $hash)) {
                    $this->invalid('quoteSignature', 'تغيّرت تفاصيل الحجز. حدّث السعر قبل التأكيد.');
                }
                $quote = $this->pricing->quote($input);
                $previous = json_decode($record->snapshot, true, 512, JSON_THROW_ON_ERROR);
                if ($quote['reconciliationRequired'] || ! hash_equals($this->quotes->pricingHash($previous), $this->quotes->pricingHash($quote))) {
                    $this->invalid('quoteSignature', 'تغيّر السعر أو الموعد. حدّث السعر قبل التأكيد.');
                }
                $firstBooking = null;
                $bookedTotalCents = 0;
                foreach ($quote['appointments'] as $appointment) {
                    $booking = $this->createHeader($customer, $input, $quote, $appointment);
                    $roomMap = $this->linkRooms($booking, $input['rooms']);
                    $operationalServices = [];
                    $this->persistAppointment($booking, $appointment, $quote, $roomMap, $operationalServices);
                    $bookingKits = [];
                    foreach ($appointment['timePeriods'] as $period) {
                        foreach ($period['materialLines'] as $line) {
                            $typeId = (int) $line['materialTypeId'];
                            if (! isset($bookingKits[$typeId])) {
                                $bookingKits[$typeId] = $line;
                            } else {
                                if ((int) $bookingKits[$typeId]['unitId'] !== (int) $line['unitId']) {
                                    throw new InvalidArgumentException('Incompatible material units.');
                                }
                                if ((float) $line['quantity'] > (float) $bookingKits[$typeId]['quantity']) {
                                    $bookingKits[$typeId] = $line;
                                }
                            }
                        }
                    }
                    foreach ($bookingKits as $kit) {
                        $this->inventory->reserveTypeLine($booking, $kit);
                    }
                    DB::table('cleaning_quote_bookings')->insert(['quote_id' => $record->id, 'cleaning_booking_id' => $booking->id, 'appointment_sequence' => (int) $appointment['sequence']]);
                    $bookedTotalCents += (int) round((float) $booking->total_price * 100);
                    $firstBooking ??= $booking;
                }
                if ($bookedTotalCents !== (int) round((float) $quote['totalPrice'] * 100)) {
                    throw new LogicException('Independent booking totals do not reconcile with the accepted quote.');
                }
                DB::table('cleaning_booking_quotes')->where('id', $record->id)->update(['idempotency_key' => $input['idempotencyKey'], 'cleaning_booking_id' => $firstBooking->id, 'updated_at' => now()]);

                return $firstBooking->fresh();
            }, 3);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['appointments' => ['تعذر توفير المواد أو الخدمات المختارة. حدّث الاختيارات وحاول مجدداً.']]);
        }
    }

    /** @return \Illuminate\Support\Collection<int, CleaningBooking> */
    public function bookingsFor(CleaningBooking $firstBooking): \Illuminate\Support\Collection
    {
        $quote = DB::table('cleaning_booking_quotes')->where('cleaning_booking_id', $firstBooking->id)->first();
        if ($quote === null) {
            return collect([$firstBooking]);
        }

        $ids = DB::table('cleaning_quote_bookings')->where('quote_id', $quote->id)
            ->orderBy('appointment_sequence')->pluck('cleaning_booking_id');

        return $ids->isEmpty() ? collect([$firstBooking]) : $ids->map(static fn ($id) => CleaningBooking::query()->findOrFail($id));
    }

    private function createHeader(User $customer, array $input, array $quote, array $appointment): CleaningBooking
    {
        $legacy = $input;
        unset($legacy['rooms'], $legacy['appointments'], $legacy['contractVersion'], $legacy['quoteId'], $legacy['quoteSignature'], $legacy['idempotencyKey']);
        $schedules = collect($appointment['roomSchedules']);
        $first = $schedules->sortBy('startAt')->first();
        $legacy['scheduledDate'] = mb_substr($first['startAt'], 0, 10);
        $legacy['scheduledTime'] = mb_substr($first['startAt'], 11, 5);
        $legacy['numberOfWorkers'] = $quote['requestedWorkerCount'] ?? $quote['requiredSimultaneousWorkers'];
        $details = $input['propertyDetails'];
        $counts = [];
        $modes = [];
        foreach ($input['rooms'] as $room) {
            $bucket = $room['roomType'].'.'.$room['size'];
            $ordinal = ($counts[$bucket] ?? 0) + 1;
            $counts[$bucket] = $ordinal;
            $modes[$bucket.'.'.$ordinal] = $room['cleaningMode'];
        }
        $details['room_cleaning_modes'] = $modes;
        $details['cleaning_mode'] = 'regular';
        $legacy['propertyDetails'] = $details;
        $booking = $this->legacy->store($customer, $legacy);
        $details = $booking->property_details;
        $details['_booking_v2_approved_total'] = $appointment['financial']['totalPrice'];
        if (array_key_exists('transportAllowancePerWorker', $quote)) {
            $details['_booking_v2_transport_allowance_per_worker'] = $quote['transportAllowancePerWorker'];
        }
        $presentation = $this->quotes->presentation($quote);
        $details['_time_period_v2'] = [
            ...$presentation,
            'appointments' => array_values(array_filter($presentation['appointments'], static fn (array $item): bool => $item['appointmentKey'] === $appointment['appointmentKey'])),
            'pricing' => [
                ...$presentation['pricing'],
                'basePrice' => $appointment['financial']['basePrice'],
                'addonsTotal' => $appointment['financial']['addonsTotal'],
                'materialsTotal' => $appointment['financial']['materialsTotal'],
                'specialServicesTotal' => $appointment['financial']['specialServicesTotal'],
                'travelFee' => $appointment['financial']['travelFee'],
                'adminMargin' => $appointment['financial']['adminMargin'],
                'totalPrice' => $appointment['financial']['totalPrice'],
                'coverage' => 'this_booking',
            ],
            'size' => [
                ...$presentation['size'],
                'estimatedWorkMinutes' => array_sum(array_column($appointment['roomSchedules'], 'estimatedWorkMinutes')),
                'estimatedDurationMinutes' => $appointment['estimatedDurationMinutes'],
            ],
        ];
        if ($quote['supervisor'] !== null) {
            $minutes = app(CleaningSupervisorHourlyPricing::class)->unionMinutes($appointment['roomSchedules']);
            $compensationRate = max(0.0, (float) (CleaningRuntimeSettings::financial()->supervisor_compensation_hourly_rate ?? 0));
            $details['_supervisor_pricing'] = [
                ...$quote['supervisor'],
                'fee' => $appointment['financial']['supervisorFee'],
                'billableMinutes' => $minutes,
                'hours' => round($minutes / 60, 4),
                'compensationHourlyRate' => $compensationRate,
                'compensationAmount' => app(CleaningPricingCalculator::class)->roundMoney($compensationRate * $minutes / 60),
            ];
        } else {
            unset($details['_supervisor_pricing']);
        }
        $booking->forceFill(['property_details' => $details, 'number_of_workers' => $quote['requestedWorkerCount'] ?? $appointment['requiredSimultaneousWorkers'], 'estimated_hours' => round(array_sum(array_column($appointment['roomSchedules'], 'estimatedWorkMinutes')) / 60, 2), 'total_hours' => round(array_sum(array_column($appointment['roomSchedules'], 'estimatedWorkMinutes')) / 60, 2), 'base_price' => $appointment['financial']['basePrice'], 'addons_total' => $appointment['financial']['addonsTotal'], 'travel_fee' => $appointment['financial']['travelFee'], 'admin_margin_amount' => $appointment['financial']['adminMargin'], 'total_price' => $appointment['financial']['totalPrice'], 'subtotal_before_discount' => $appointment['financial']['totalPrice'], 'is_pricing_final' => $presentation['pricing']['isPricingFinal']])->save();

        return $booking;
    }

    private function linkRooms(CleaningBooking $booking, array $rooms): array
    {
        $available = $booking->rooms()->orderBy('id')->get();
        $used = [];
        $map = [];
        foreach ($rooms as $input) {
            $room = $available->first(static fn ($room): bool => $room->room_type === $input['roomType'] && $room->room_size === $input['size'] && ! isset($used[$room->id]));
            if ($room === null) {
                $this->invalid('rooms', 'تعذر ربط الغرفة بالحجز.');
            }
            $used[$room->id] = true;
            $room->forceFill(['client_key' => $input['roomKey'], 'cleaning_mode' => $input['cleaningMode']])->save();
            $map[$input['roomKey']] = $room->id;
        }

        return $map;
    }

    private function persistAppointment(CleaningBooking $booking, array $appointment, array $quote, array $roomMap, array &$operationalServices): void
    {
        $scheduleMap = [];
        $byRoom = [];
        foreach ($appointment['roomSchedules'] as $schedule) {
            $row = CleaningRoomSchedule::query()->create(['cleaning_booking_id' => $booking->id, 'cleaning_booking_room_id' => $roomMap[$schedule['roomKey']], 'schedule_key' => $schedule['scheduleKey'], 'timezone' => $quote['timezone'], 'starts_at' => CarbonImmutable::parse($schedule['startAt'])->utc(), 'ends_at' => CarbonImmutable::parse($schedule['endAt'])->utc(), 'required_workers' => $schedule['numberOfWorkers'], 'work_minutes' => $schedule['estimatedWorkMinutes'], 'duration_minutes' => $schedule['estimatedDurationMinutes']]);
            $scheduleMap[$schedule['scheduleKey']] = $row->id;
            $byRoom[$schedule['roomKey']] = $row->id;
        }
        foreach ($appointment['timePeriods'] as $period) {
            $row = CleaningTimePeriod::query()->create(['cleaning_booking_id' => $booking->id, 'period_key' => $period['periodKey'], 'timezone' => $quote['timezone'], 'starts_at' => CarbonImmutable::parse($period['startAt'])->utc(), 'ends_at' => CarbonImmutable::parse($period['endAt'])->utc(), 'materials_choice' => $period['materials']['choice']]);
            $row->schedules()->attach(array_map(static fn (string $key): int => $scheduleMap[$key], $period['roomScheduleKeys']));
            foreach ($period['materialLines'] as $line) {
                DB::table('cleaning_time_period_materials')->insert(['cleaning_time_period_id' => $row->id, 'cleaning_material_type_id' => $line['materialTypeId'], 'quantity' => $line['quantity'], 'unit' => $line['unit'], 'snapshot' => json_encode($line, JSON_THROW_ON_ERROR)]);
            }
            foreach ($period['serviceLines'] as $service) {
                $line = $service['quoteLine'];
                $id = (int) $service['specialServiceId'];
                if (! isset($operationalServices[$id])) {
                    $operationalServices[$id] = CleaningBookingSpecialService::query()->create(['cleaning_booking_id' => $booking->id, 'cleaning_special_service_id' => $id, 'service_name' => $line['name'], 'pricing_unit' => $line['pricingUnit'], 'dirtiness_level' => (string) ($line['dirtinessLevel'] ?? ''), 'quantity' => 0, 'base_unit_price' => $line['baseUnitPrice'], 'price_multiplier' => $line['priceMultiplier'], 'total_price' => 0, 'equipment_snapshot' => $line['equipment'], 'execution_status' => 'pending', 'financial_snapshot' => $line['financialSnapshot']]);
                }
                $parent = $operationalServices[$id];
                $parent->quantity += $line['quantity'];
                $parent->total_price += $line['totalPrice'];
                $parent->save();
                $itemLine = $line['items'][0];
                $item = CleaningBookingSpecialServiceItem::query()->create(['cleaning_booking_special_service_id' => $parent->id, 'cleaning_dirtiness_level_id' => $itemLine['dirtinessLevelId'], 'quantity' => $itemLine['quantity'], 'dirtiness_level' => $itemLine['dirtinessLevel'], 'base_unit_price' => $itemLine['baseUnitPrice'], 'price_multiplier' => $itemLine['priceMultiplier'], 'total_price' => $itemLine['totalPrice']]);
                DB::table('cleaning_time_period_services')->insert(['cleaning_time_period_id' => $row->id, 'cleaning_room_schedule_id' => $byRoom[$service['targetRoomKey']], 'cleaning_special_service_id' => $id, 'cleaning_booking_special_service_item_id' => $item->id, 'service_key' => $service['serviceKey'], 'quantity' => $service['quantity'], 'unit' => $service['unit'], 'dirtiness_level' => $service['dirtinessLevel'], 'snapshot' => json_encode($service, JSON_THROW_ON_ERROR)]);
            }
        }
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
