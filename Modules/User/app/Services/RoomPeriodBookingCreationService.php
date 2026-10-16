<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSpecialService;
use Modules\Cleaning\Models\CleaningBookingSpecialServiceItem;
use Modules\Cleaning\Services\CleaningMaterialInventoryService;

/**
 * User-app room-period booking materialization. Uses the existing booking
 * service to preserve booking refs and legacy room assignment compatibility,
 * then writes the period quote and separate period configurations atomically.
 */
final class RoomPeriodBookingCreationService
{
    public function __construct(
        private readonly UserCleaningOrderService $legacyStore,
        private readonly RoomPeriodPricingService $periodPricing,
        private readonly CleaningMaterialInventoryService $materialsInventory,
    ) {}

    /** @param array<string,mixed> $data */
    public function create(User $customer, array $data, string $quoteSignature): CleaningBooking
    {
        $quote = $this->periodPricing->quote($data);
        if (! hash_equals($this->signature($quote, $data, (int) $customer->id), $quoteSignature)) {
            throw ValidationException::withMessages([
                'quoteSignature' => ['The booking details or price have changed. Review the updated quote before confirming.'],
            ]);
        }

        if (isset($data['couponCode']) && mb_trim((string) $data['couponCode']) !== '') {
            throw ValidationException::withMessages([
                'couponCode' => ['Period-booking coupon pricing is being activated separately; no booking was created.'],
            ]);
        }

        return DB::transaction(function () use ($customer, $data, $quote): CleaningBooking {
            $periods = $data['roomPeriods'];
            $first = $periods[0];

            // The legacy booking header is preserved for current dashboards
            // and mobile clients, while the new periods carry the real timing.
            $legacy = $data;
            unset($legacy['roomPeriods'], $legacy['quoteSignature']);
            $legacy['scheduledDate'] = substr($quote['earliestStart'], 0, 10);
            $legacy['scheduledTime'] = substr($quote['earliestStart'], 11, 5);
            $legacy['numberOfWorkers'] = (int) $quote['requiredSimultaneousWorkers'];
            $legacy['assignmentMode'] = 'open_count';
            unset($legacy['preferredWorkerId'], $legacy['preferredWorkerIds'], $legacy['materials'], $legacy['requestMaterials'], $legacy['specialServices']);

            // Bridge stable room keys into the old room-size identifiers so
            // existing per-room cleaning mode readers stay compatible.
            $details = (array) $data['propertyDetails'];
            $sizeBucketCounters = [];
            $modes = [];
            $sortedPeriods = array_filter($periods, static fn (array $p): bool => (int) $p['visitSequence'] === 1);
            usort($sortedPeriods, static fn (array $a, array $b): int =>
                strcmp((string) $a['clientKey'], (string) $b['clientKey']));
            foreach ($sortedPeriods as $period) {
                $bucket = $period['roomType'].'.'.$period['size'];
                $index = ($sizeBucketCounters[$bucket] ?? 0) + 1;
                $sizeBucketCounters[$bucket] = $index;
                $modes[$bucket.'.'.$index] = $period['cleaningMode'];
            }
            $details['room_cleaning_modes'] = $modes;
            $details['cleaning_mode'] = 'regular';
            $legacy['propertyDetails'] = $details;

            $booking = $this->legacyStore->store($customer, $legacy);
            $detailsWithFrozenQuote = (array) ($booking->property_details ?? []);
            // Workers may incur different real travel charges after acceptance.
            // Their settlement can change, but the quoted customer amount is
            // fixed; differences belong to platform settlement, not the user.
            $detailsWithFrozenQuote['_room_period_v2_approved_total'] =
                (float) $quote['totalPrice'];
            $detailsWithFrozenQuote['_room_period_v2_quote_algorithm'] =
                $quote['algorithmVersion'];
            $booking->forceFill([
                'property_details' => $detailsWithFrozenQuote,
                'number_of_workers' => (int) $quote['requiredSimultaneousWorkers'],
                'estimated_hours' => round($quote['estimatedWorkMinutes'] / 60, 2),
                'total_hours' => round($quote['estimatedWorkMinutes'] / 60, 2),
                'base_price' => (float) $quote['basePrice'],
                'addons_total' => (float) $quote['addonsTotal'],
                'travel_fee' => (float) $quote['travelFee'],
                'admin_margin_amount' => (float) $quote['adminMargin'],
                'total_price' => (float) $quote['totalPrice'],
                'is_pricing_final' => false,
            ])->save();

            // Stable per-type ordinal client IDs are distinct from legacy
            // room-size keys. Link by type+size, never by positional guessing.
            $availableRooms = $booking->rooms()->orderBy('id')->get();
            $usedRoomIds = [];
            $byKey = [];
            foreach ($sortedPeriods as $period) {
                $matched = $availableRooms->first(static function ($room) use ($period, &$usedRoomIds): bool {
                    return $room->room_type === $period['roomType']
                        && $room->room_size === $period['size']
                        && ! in_array((int) $room->id, $usedRoomIds, true);
                });
                if ($matched !== null) {
                    $usedRoomIds[] = (int) $matched->id;
                    $byKey[$period['clientKey']] = (int) $matched->id;
                }
            }

            foreach ($periods as $idx => $period) {
                $line = (array) ($quote['roomPeriods'][$idx] ?? []);
                $booking->roomPeriods()->create([
                    'client_key' => $period['clientKey'],
                    'visit_sequence' => $period['visitSequence'],
                    'cleaning_booking_room_id' => $byKey[$period['clientKey']] ?? null,
                    'room_type' => $period['roomType'],
                    'room_size' => $period['size'],
                    'cleaning_mode' => $period['cleaningMode'],
                    'scheduled_date' => $period['scheduledDate'],
                    'scheduled_time' => $period['scheduledTime'],
                    'required_workers' => $period['numberOfWorkers'],
                    'estimated_work_minutes' => $line['estimatedWorkMinutes'] ?? null,
                    'estimated_duration_minutes' => $line['estimatedDurationMinutes'] ?? null,
                    'materials' => $period['materials'],
                    'special_services' => $period['specialServices'],
                    'notes' => $period['notes'] ?? null,
                    'base_price' => $line['basePrice'] ?? null,
                    'addons_total' => (float) ($line['specialServicesTotal'] ?? 0),
                    'total_price' => round((float) ($line['basePrice'] ?? 0) + (float) ($line['specialServicesTotal'] ?? 0), 2),
                    'pricing_snapshot' => $line,
                ]);
            }

            // The worker/inventory modules still consume booking-level
            // material and special-service ledgers. Materialize a single
            // inventory reservation for each shared kit, plus itemized
            // specialized services, without charging any additional amount.
            $this->persistOperationalExtras($booking, $quote);

            return $booking->fresh();
        });
    }

    /**
     * Materialize operational ledgers from signed period quote snapshots.
     * Per-room assignments and timestamps continue to live in roomPeriods.
     *
     * @param array<string,mixed> $quote
     */
    private function persistOperationalExtras(CleaningBooking $booking, array $quote): void
    {
        $materialLines = [];
        $specialsById = [];
        foreach ((array) ($quote['roomPeriods'] ?? []) as $period) {
            $visit = (int) ($period['visitSequence'] ?? 1);
            foreach ((array) ($period['materials'] ?? []) as $material) {
                $id = (int) ($material['materialTypeId'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $key = $visit.':'.$id;
                if (! isset($materialLines[$key])
                    || (float) ($material['totalPrice'] ?? 0)
                    > (float) ($materialLines[$key]['totalPrice'] ?? 0)) {
                    $materialLines[$key] = $material;
                }
            }

            foreach ((array) ($period['specialServices'] ?? []) as $line) {
                $id = (int) ($line['specialServiceId'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                if (! isset($specialsById[$id])) {
                    $specialsById[$id] = [
                        'line' => $line,
                        'quantity' => 0.0,
                        'price' => 0.0,
                        'items' => [],
                    ];
                }
                $specialsById[$id]['quantity'] += (float) ($line['quantity'] ?? 0);
                $specialsById[$id]['price'] += (float) ($line['totalPrice'] ?? 0);
                array_push($specialsById[$id]['items'], ...(array) ($line['items'] ?? []));
            }
        }

        foreach ($materialLines as $line) {
            $this->materialsInventory->reserveTypeLine($booking, $line);
        }

        foreach ($specialsById as $id => $summary) {
            $line = $summary['line'];
            $quantity = round((float) $summary['quantity'], 3);
            $total = round((float) $summary['price'], 2);
            $unit = (float) ($line['baseUnitPrice'] ?? 0);
            $weightedMultiplier = $quantity > 0 && $unit > 0
                ? round($total / ($unit * $quantity), 3)
                : 1.0;
            $bookingService = CleaningBookingSpecialService::query()->create([
                'cleaning_booking_id' => $booking->id,
                'cleaning_special_service_id' => (int) $id,
                'service_name' => (string) ($line['name'] ?? ''),
                'pricing_unit' => (string) ($line['pricingUnit'] ?? 'piece'),
                'dirtiness_level' => (string) ($line['dirtinessLevel'] ?? ''),
                'quantity' => $quantity,
                'base_unit_price' => $unit,
                'price_multiplier' => $weightedMultiplier,
                'total_price' => $total,
                'equipment_snapshot' => (array) ($line['equipment'] ?? []),
                'notes' => $line['notes'] ?? null,
                'execution_status' => 'pending',
                'financial_snapshot' => (array) ($line['financialSnapshot'] ?? []),
            ]);

            foreach ($summary['items'] as $item) {
                CleaningBookingSpecialServiceItem::query()->create([
                    'cleaning_booking_special_service_id' => $bookingService->id,
                    'cleaning_dirtiness_level_id' => $item['dirtinessLevelId'] ?? null,
                    'quantity' => (float) ($item['quantity'] ?? 0),
                    'dirtiness_level' => $item['dirtinessLevel'] ?? null,
                    'price_multiplier' => (float) ($item['priceMultiplier'] ?? 1),
                    'base_unit_price' => (float) ($item['baseUnitPrice'] ?? 0),
                    'total_price' => (float) ($item['totalPrice'] ?? 0),
                    'notes' => $item['notes'] ?? null,
                    'before_images' => (array) ($item['beforeImages'] ?? []),
                    'after_images' => (array) ($item['afterImages'] ?? []),
                ]);
            }
        }
    }

    /** @param array<string,mixed> $quote */
    public function signature(array $quote, array $payload, int $userId): string
    {
        // Bind the quote to the customer, address, and complete raw period
        // selections (including zero-priced requirements and room notes).
        // This avoids reusing another customer's quote or altering materials
        // while presenting an unchanged amount.
        $signed = [
            'userId' => $userId,
            'propertyType' => (string) ($payload['propertyType'] ?? ''),
            'addressId' => (int) ($payload['addressId'] ?? 0),
            'addressLatitude' => $payload['addressLatitude'] ?? null,
            'addressLongitude' => $payload['addressLongitude'] ?? null,
            'roomPeriods' => array_values((array) ($payload['roomPeriods'] ?? [])),
            'quote' => $quote,
        ];

        return hash_hmac(
            'sha256',
            json_encode($signed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            (string) config('app.key'),
        );
    }
}
