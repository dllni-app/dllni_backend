<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Models\CancellationPolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;

final class UserCleaningOrderService
{
    public function store(User $user, array $validated): CleaningBooking
    {
        return DB::transaction(function () use ($user, $validated): CleaningBooking {
            $basePrice = (float) ($validated['basePrice'] ?? 0);
            $travelFee = (float) ($validated['travelFee'] ?? 0);
            $addonsTotal = (float) ($validated['addonsTotal'] ?? 0);
            $totalPrice = (float) ($validated['totalPrice'] ?? ($basePrice + $travelFee + $addonsTotal));

            $booking = CleaningBooking::create([
                'customer_id' => $user->id,
                'worker_id' => null,
                'preferred_worker_id' => $validated['preferredWorkerId'] ?? null,
                'cancellation_policy_id' => $validated['cancellationPolicyId'] ?? $this->defaultCancellationPolicyId(),
                'billing_policy_id' => $validated['billingPolicyId'] ?? $this->defaultBillingPolicyId(),
                'booking_number' => $this->generateBookingNumber(),
                'status' => CleaningBookingStatus::Pending,
                'property_type' => $validated['propertyType'],
                'property_details' => $validated['propertyDetails'],
                'address_latitude' => $validated['addressLatitude'] ?? null,
                'address_longitude' => $validated['addressLongitude'] ?? null,
                'estimated_sqm' => $validated['estimatedSqm'] ?? null,
                'estimated_hours' => $validated['totalHours'],
                'scheduled_date' => $validated['scheduledDate'],
                'scheduled_time' => $validated['scheduledTime'],
                'total_hours' => $validated['totalHours'],
                'base_price' => $basePrice,
                'addons_total' => $addonsTotal,
                'travel_fee' => $travelFee,
                'cancellation_fee' => 0,
                'total_price' => $totalPrice,
                'terms_accepted' => true,
            ]);

            return $booking->fresh();
        });
    }

    public function update(CleaningBooking $booking, array $validated): CleaningBooking
    {
        if (in_array($booking->status, [CleaningBookingStatus::InProgress, CleaningBookingStatus::Completed, CleaningBookingStatus::Cancelled], true)) {
            throw ValidationException::withMessages([
                'order' => ['Order cannot be edited in current status.'],
            ]);
        }

        return DB::transaction(function () use ($booking, $validated): CleaningBooking {
            $updates = [];

            if (array_key_exists('propertyDetails', $validated)) {
                $updates['property_details'] = $validated['propertyDetails'];
            }
            if (array_key_exists('estimatedSqm', $validated)) {
                $updates['estimated_sqm'] = $validated['estimatedSqm'];
            }
            if (array_key_exists('totalHours', $validated)) {
                $updates['total_hours'] = $validated['totalHours'];
                $updates['estimated_hours'] = $validated['totalHours'];
            }
            if (array_key_exists('scheduledDate', $validated)) {
                $updates['scheduled_date'] = $validated['scheduledDate'];
            }
            if (array_key_exists('scheduledTime', $validated)) {
                $updates['scheduled_time'] = $validated['scheduledTime'];
            }
            if (array_key_exists('addressLatitude', $validated)) {
                $updates['address_latitude'] = $validated['addressLatitude'];
            }
            if (array_key_exists('addressLongitude', $validated)) {
                $updates['address_longitude'] = $validated['addressLongitude'];
            }
            if (array_key_exists('preferredWorkerId', $validated)) {
                $updates['preferred_worker_id'] = $validated['preferredWorkerId'];
            }
            if (array_key_exists('basePrice', $validated)) {
                $updates['base_price'] = $validated['basePrice'];
            }
            if (array_key_exists('travelFee', $validated)) {
                $updates['travel_fee'] = $validated['travelFee'];
            }
            if (array_key_exists('addonsTotal', $validated)) {
                $updates['addons_total'] = $validated['addonsTotal'];
            }
            if (array_key_exists('totalPrice', $validated)) {
                $updates['total_price'] = $validated['totalPrice'];
            } elseif (
                array_key_exists('basePrice', $validated)
                || array_key_exists('travelFee', $validated)
                || array_key_exists('addonsTotal', $validated)
            ) {
                $basePrice = (float) ($updates['base_price'] ?? $booking->base_price);
                $travelFee = (float) ($updates['travel_fee'] ?? $booking->travel_fee);
                $addonsTotal = (float) ($updates['addons_total'] ?? $booking->addons_total);
                $updates['total_price'] = $basePrice + $travelFee + $addonsTotal;
            }

            if ($updates !== []) {
                $booking->update($updates);
            }

            return $booking->fresh();
        });
    }

    public function cancel(CleaningBooking $booking, ?string $reason = null): CleaningBooking
    {
        if (! in_array($booking->status, [CleaningBookingStatus::Pending, CleaningBookingStatus::WorkerAssigned], true)) {
            throw ValidationException::withMessages([
                'order' => ['Order cannot be cancelled in current status.'],
            ]);
        }

        $booking->update([
            'status' => CleaningBookingStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        return $booking->fresh();
    }

    private function defaultCancellationPolicyId(): ?int
    {
        $id = CancellationPolicy::query()
            ->where('module', 'cleaning')
            ->where('is_active', true)
            ->where('is_default', true)
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function defaultBillingPolicyId(): ?int
    {
        $id = CleaningBillingPolicy::query()
            ->where('is_active', true)
            ->where('is_default', true)
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function generateBookingNumber(): string
    {
        do {
            $bookingNumber = 'CLN-USER-' . Str::upper(Str::random(8));
        } while (CleaningBooking::query()->where('booking_number', $bookingNumber)->exists());

        return $bookingNumber;
    }
}
