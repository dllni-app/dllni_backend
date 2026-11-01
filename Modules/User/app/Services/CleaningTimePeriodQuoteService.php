<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Cleaning\Models\CleaningSpecialService;

final class CleaningTimePeriodQuoteService
{
    public function __construct(private readonly CleaningTimePeriodPricingService $pricing) {}

    public function estimate(User $customer, array $input): array
    {
        $quote = $this->pricing->quote($input);
        $response = $this->presentation($quote);
        if ($quote['reconciliationRequired']) {
            return [...$response, 'quoteId' => null, 'quoteSignature' => null, 'quoteExpiresAt' => null];
        }
        $id = (string) Str::uuid();
        $hash = $this->requestHash($input);
        $expires = now()->addMinutes((int) config('cleaning_booking_v2.quote_ttl_minutes'));
        $signature = hash_hmac('sha256', $id.'|'.$customer->id.'|'.$hash.'|'.$this->pricingHash($quote).'|'.$expires->timestamp, (string) config('app.key'));
        DB::table('cleaning_booking_quotes')->insert(['id' => $id, 'customer_id' => $customer->id, 'request_hash' => $hash, 'signature' => $signature, 'snapshot' => json_encode($quote, JSON_THROW_ON_ERROR), 'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now()]);

        return [...$response, 'quoteId' => $id, 'quoteSignature' => $signature, 'quoteExpiresAt' => $expires->toIso8601String()];
    }

    public function presentation(array $quote): array
    {
        $appointments = array_map(static function (array $appointment): array {
            unset($appointment['financial']);
            $appointment['roomSchedules'] = array_map(static function (array $schedule): array {
                unset($schedule['basePrice']);

                return $schedule;
            }, $appointment['roomSchedules']);
            $appointment['timePeriods'] = array_map(static function (array $period): array {
                unset($period['materialLines'], $period['serviceLines']);

                return $period;
            }, $appointment['timePeriods']);

            $appointment['startAt'] = collect($appointment['roomSchedules'])->min('startAt');
            $appointment['endAt'] = collect($appointment['roomSchedules'])->max('endAt');

            return $appointment;
        }, $quote['appointments']);
        $catalog = CleaningSpecialService::query()->where('is_active', true)->with(['dirtinessLevels', 'dirtinessRules'])->get()->map(static fn (CleaningSpecialService $service): array => [
            'id' => $service->id, 'specialServiceId' => $service->id, 'name' => $service->name, 'description' => $service->description, 'imageUrl' => $service->imageUrl(), 'unitCode' => $service->unit_code, 'pricingUnit' => $service->pricing_unit, 'inputType' => $service->input_type, 'supportsDirtiness' => $service->supports_dirtiness, 'estimatedDurationMinutes' => $service->estimated_duration_minutes,
            'dirtinessLevels' => $service->supports_dirtiness ? ['light', 'heavy'] : [],
        ])->all();

        // The existing fixed worker allowance is included in the signed quote.
        $travelFeePending = false;

        return ['materialsPreview' => collect($appointments)->flatMap(static fn (array $appointment): array => $appointment['timePeriods'])->flatMap(static fn (array $period): array => $period['materialsPreview'])->unique('materialTypeId')->values()->all(), 'contractVersion' => 2, 'rooms' => $quote['rooms'], 'appointments' => $appointments, 'pricing' => ['basePrice' => $quote['basePrice'], 'addonsTotal' => $quote['addonsTotal'], 'materialsTotal' => $quote['materialsTotal'], 'specialServicesTotal' => $quote['specialServicesTotal'], 'travelFee' => $quote['travelFee'], 'adminMargin' => $quote['adminMargin'], 'totalPrice' => $quote['totalPrice'], 'currency' => $quote['currency'], 'isPricingFinal' => ! $travelFeePending, 'travelFeePending' => $travelFeePending, 'coverage' => 'all_appointments', 'supervisor' => $quote['supervisor'] ?? null], 'size' => ['estimatedWorkMinutes' => $quote['estimatedWorkMinutes'], 'estimatedDurationMinutes' => array_sum(array_column($appointments, 'estimatedDurationMinutes')), 'estimatedHours' => round($quote['estimatedWorkMinutes'] / 60, 2)], 'requiredSimultaneousWorkers' => $quote['requiredSimultaneousWorkers'], 'reconciliationRequired' => $quote['reconciliationRequired'], 'pricingAlgorithm' => $quote['algorithmVersion'], 'schedulingPolicy' => ['timezone' => $quote['timezone'], 'minimumWorkers' => 1, 'maximumWorkers' => min(6, (int) config('cleaning_booking_v2.max_workers')), 'maximumDurationMinutes' => (int) config('cleaning_booking_v2.max_duration_minutes'), 'crossMidnightAllowed' => false], 'specialServicesCatalog' => $catalog];
    }

    public function requestHash(array $input): string
    {
        $keys = ['contractVersion', 'propertyType', 'propertyDetails', 'addressId', 'addressLatitude', 'addressLongitude', 'neighborhoodId', 'neighborhood', 'rooms', 'appointments', 'recurrence', 'numberOfWorkers', 'assignmentMode', 'preferredWorkerId', 'preferredWorkerIds', 'genderPreference', 'workerScope', 'specificWorkerIds', 'couponCode', 'workEnvironmentConfirmation'];
        $payload = array_intersect_key($input, array_flip($keys));
        // Legacy preferred-worker request normalizers may derive a team count
        // differently between estimate and create. Worker identities are the
        // binding input in that mode; peak demand is revalidated on creation.
        if (! empty($payload['preferredWorkerIds']) || ! empty($payload['preferredWorkerId'])) {
            unset($payload['numberOfWorkers']);
        }
        foreach (['addressLatitude', 'addressLongitude'] as $coordinate) {
            if (isset($payload[$coordinate])) {
                $payload[$coordinate] = round((float) $payload[$coordinate], 7);
            }
        }

        return $this->hash($payload);
    }

    public function pricingHash(array $quote): string
    {
        return $this->hash($quote);
    }

    private function hash(array $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }

            return array_map($normalize, $item);
        };

        return hash('sha256', json_encode($normalize($value), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
