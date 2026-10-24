<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSpecialService;
use Modules\Cleaning\Models\CleaningBookingSpecialServiceItem;
use Modules\Cleaning\Models\CleaningRoomSchedule;
use Modules\Cleaning\Models\CleaningTimePeriod;
use Modules\Cleaning\Services\CleaningMaterialInventoryService;

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
                $booking = $this->createHeader($customer, $input, $quote);
                $roomMap = $this->linkRooms($booking, $input['rooms']);
                $operationalServices = [];
                foreach ($quote['visits'] as $visit) {
                    $this->persistVisit($booking, $visit, $quote, $roomMap, $operationalServices);
                }
                // Booking inventory has one row per physical product. Reserve each type
                // once using the sum across visits, while period snapshots keep their
                // individual usage/pricing and the quote totals remain visit-specific.
                $bookingKits = [];
                foreach ($quote['materialKits'] as $kit) {
                    $typeId = (int) $kit['materialTypeId'];
                    if (! isset($bookingKits[$typeId])) {
                        $bookingKits[$typeId] = $kit;

                        continue;
                    }
                    if ((int) $bookingKits[$typeId]['unitId'] !== (int) $kit['unitId']) {
                        throw new InvalidArgumentException('Incompatible units for one material type.');
                    }
                    $bookingKits[$typeId]['quantity'] = round(
                        (float) $bookingKits[$typeId]['quantity'] + (float) $kit['quantity'],
                        3
                    );
                    $bookingKits[$typeId]['totalPrice'] = round(
                        (float) $bookingKits[$typeId]['totalPrice'] + (float) $kit['totalPrice'],
                        2
                    );
                }
                foreach ($bookingKits as $kit) {
                    $this->inventory->reserveTypeLine($booking, $kit);
                }
                DB::table('cleaning_booking_quotes')->where('id', $record->id)->update(['idempotency_key' => $input['idempotencyKey'], 'cleaning_booking_id' => $booking->id, 'updated_at' => now()]);

                return $booking->fresh();
            }, 3);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['visits' => ['تعذر توفير المواد أو الخدمات المختارة. حدّث الاختيارات وحاول مجدداً.']]);
        }
    }

    private function createHeader(User $customer, array $input, array $quote): CleaningBooking
    {
        $legacy = $input;
        unset($legacy['rooms'], $legacy['visits'], $legacy['contractVersion'], $legacy['quoteId'], $legacy['quoteSignature'], $legacy['idempotencyKey']);
        $schedules = collect($quote['visits'])->flatMap(fn (array $visit): array => $visit['roomSchedules']);
        $first = $schedules->sortBy('startAt')->first();
        $legacy['scheduledDate'] = mb_substr($first['startAt'], 0, 10);
        $legacy['scheduledTime'] = mb_substr($first['startAt'], 11, 5);
        $legacy['numberOfWorkers'] = $quote['requiredSimultaneousWorkers'];
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
        $details['_room_period_v2_approved_total'] = $quote['totalPrice'];
        $details['_time_period_v2'] = $this->quotes->presentation($quote);
        if ($quote['supervisor'] !== null) {
            $details['_supervisor_pricing'] = $quote['supervisor'];
        }
        $booking->forceFill(['property_details' => $details, 'number_of_workers' => $quote['requiredSimultaneousWorkers'], 'estimated_hours' => round($quote['estimatedWorkMinutes'] / 60, 2), 'total_hours' => round($quote['estimatedWorkMinutes'] / 60, 2), 'base_price' => $quote['basePrice'], 'addons_total' => $quote['addonsTotal'], 'travel_fee' => $quote['travelFee'], 'admin_margin_amount' => $quote['adminMargin'], 'total_price' => $quote['totalPrice'], 'subtotal_before_discount' => $quote['totalPrice'], 'is_pricing_final' => true])->save();

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

    private function persistVisit(CleaningBooking $booking, array $visit, array $quote, array $roomMap, array &$operationalServices): void
    {
        $first = collect($visit['roomSchedules'])->sortBy('startAt')->first();
        $work = array_sum(array_column($visit['roomSchedules'], 'estimatedWorkMinutes'));
        $fraction = $quote['estimatedWorkMinutes'] > 0 ? $work / $quote['estimatedWorkMinutes'] : 1 / count($quote['visits']);
        $requiredWorkers = max(1, (int) $visit['requiredSimultaneousWorkers']);
        $session = $booking->sessions()->create(['sequence' => $visit['sequence'], 'session_type' => count($quote['visits']) > 1 ? 'recurring_cleaning' : null, 'calculation_mode' => 'task', 'scheduled_date' => mb_substr($first['startAt'], 0, 10), 'scheduled_time' => mb_substr($first['startAt'], 11, 5), 'duration_hours' => round($visit['estimatedDurationMinutes'] / 60, 2), 'required_workers' => $requiredWorkers, 'supervision_mode' => $requiredWorkers > 1 ? CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR : CleaningBookingSession::SUPERVISION_MODE_WORKER, 'supervision_initialized_at' => now(), 'materials_total' => $visit['financial']['materialsTotal'], 'special_services_total' => $visit['financial']['specialServicesTotal'], 'coverage_status' => 'searching', 'status' => 'scheduled', 'base_price' => $visit['financial']['basePrice'], 'addons_total' => $visit['financial']['addonsTotal'], 'travel_fee' => $visit['financial']['travelFee'], 'admin_margin_amount' => $visit['financial']['adminMargin'], 'total_price' => $visit['financial']['totalPrice'], 'is_pricing_final' => true, 'pricing_snapshot' => ['contractVersion' => 2, 'visitKey' => $visit['visitKey'], 'includedAdminMargin' => $visit['financial']['includedAdminMargin'], 'cashRoundingAdjustment' => $visit['financial']['cashRoundingAdjustment']]]);
        $scheduleMap = [];
        $byRoom = [];
        foreach ($visit['roomSchedules'] as $schedule) {
            $row = CleaningRoomSchedule::query()->create(['cleaning_booking_id' => $booking->id, 'cleaning_booking_session_id' => $session->id, 'cleaning_booking_room_id' => $roomMap[$schedule['roomKey']], 'schedule_key' => $schedule['scheduleKey'], 'visit_key' => $visit['visitKey'], 'timezone' => $quote['timezone'], 'starts_at' => CarbonImmutable::parse($schedule['startAt'])->utc(), 'ends_at' => CarbonImmutable::parse($schedule['endAt'])->utc(), 'required_workers' => $schedule['numberOfWorkers'], 'work_minutes' => $schedule['estimatedWorkMinutes'], 'duration_minutes' => $schedule['estimatedDurationMinutes']]);
            $scheduleMap[$schedule['scheduleKey']] = $row->id;
            $byRoom[$schedule['roomKey']] = $row->id;
        }
        foreach ($visit['timePeriods'] as $period) {
            $row = CleaningTimePeriod::query()->create(['cleaning_booking_id' => $booking->id, 'cleaning_booking_session_id' => $session->id, 'period_key' => $period['periodKey'], 'timezone' => $quote['timezone'], 'starts_at' => CarbonImmutable::parse($period['startAt'])->utc(), 'ends_at' => CarbonImmutable::parse($period['endAt'])->utc(), 'materials_choice' => $period['materials']['choice']]);
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
                $parent->sessions()->syncWithoutDetaching([$session->id]);
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
