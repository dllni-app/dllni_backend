<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\SystemAlertStatus;
use App\Models\SystemAlert;
use Carbon\CarbonImmutable;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Enums\RestaurantDisputeStatus;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\RestaurantOrderDispute;

final class RestaurantSystemAlertGenerator
{
    public function handle(): int
    {
        $created = 0;

        $created += $this->generateDelayedRatingAlerts();
        $created += $this->generateFrozenProgressAlerts();
        $created += $this->generateSosAlerts();
        $created += $this->generateTimeEndAlerts();
        $created += $this->generateOverdueCompletionAlerts();

        return $created;
    }

    private function generateDelayedRatingAlerts(): int
    {
        $now = CarbonImmutable::now();
        $orders = Order::query()
            ->whereNotNull('completed_at')
            ->where('completed_at', '<=', $now->subHour())
            ->whereIn('status', [OrderStatus::Completed])
            ->get();

        $created = 0;

        foreach ($orders as $order) {
            $hasCustomerReview = $order->reviews()->exists();
            $hasRestaurantReview = $order->customerReviews()->exists();

            if ($hasCustomerReview && $hasRestaurantReview) {
                continue;
            }

            $severity = $order->completed_at?->lte($now->subHours(3)) ? AlertSeverity::Critical : AlertSeverity::Medium;

            $created += $this->createAlertIfMissing(
                bookingId: $order->id,
                alertType: AlertType::DelayedRating,
                severity: $severity,
                payload: [
                    'order_number' => $order->order_number,
                    'customer_reviewed' => $hasCustomerReview,
                    'restaurant_reviewed' => $hasRestaurantReview,
                ],
            );
        }

        return $created;
    }

    private function generateFrozenProgressAlerts(): int
    {
        $frozenSince = CarbonImmutable::now()->subMinutes(20);

        $orders = Order::query()
            ->whereIn('status', [OrderStatus::Accepted, OrderStatus::Preparing])
            ->where('updated_at', '<=', $frozenSince)
            ->get();

        $created = 0;

        foreach ($orders as $order) {
            $created += $this->createAlertIfMissing(
                bookingId: $order->id,
                alertType: AlertType::FrozenGPS,
                severity: AlertSeverity::High,
                payload: [
                    'order_number' => $order->order_number,
                    'last_status_update_at' => $order->updated_at?->toDateTimeString(),
                ],
            );
        }

        return $created;
    }

    private function generateSosAlerts(): int
    {
        $disputes = RestaurantOrderDispute::query()
            ->whereIn('status', [RestaurantDisputeStatus::Open, RestaurantDisputeStatus::UnderReview])
            ->where(function ($query): void {
                $query->where('description', 'like', '%sos%')
                    ->orWhere('description', 'like', '%emergency%')
                    ->orWhere('admin_note', 'like', '%sos%')
                    ->orWhere('admin_note', 'like', '%emergency%');
            })
            ->with('order:id,order_number')
            ->get();

        $created = 0;

        foreach ($disputes as $dispute) {
            $order = $dispute->order;

            if (! $order) {
                continue;
            }

            $created += $this->createAlertIfMissing(
                bookingId: $order->id,
                alertType: AlertType::SOSTriggered,
                severity: AlertSeverity::Critical,
                payload: [
                    'order_number' => $order->order_number,
                    'dispute_id' => $dispute->id,
                    'ticket_number' => $dispute->ticket_number,
                ],
            );
        }

        return $created;
    }

    private function generateTimeEndAlerts(): int
    {
        $now = CarbonImmutable::now();
        $orders = Order::query()
            ->whereIn('status', [OrderStatus::Accepted, OrderStatus::Preparing])
            ->whereNotNull('accepted_at')
            ->whereNotNull('estimated_preparation_minutes')
            ->get();

        $created = 0;

        foreach ($orders as $order) {
            $expectedEnd = $order->accepted_at?->copy()->addMinutes((int) $order->estimated_preparation_minutes);

            if (! $expectedEnd) {
                continue;
            }

            if ($now->lt($expectedEnd->copy()->subMinutes(15)) || $now->gt($expectedEnd)) {
                continue;
            }

            $created += $this->createAlertIfMissing(
                bookingId: $order->id,
                alertType: AlertType::TimeExpired,
                severity: AlertSeverity::Medium,
                payload: [
                    'order_number' => $order->order_number,
                    'expected_end_at' => $expectedEnd->toDateTimeString(),
                ],
            );
        }

        return $created;
    }

    private function generateOverdueCompletionAlerts(): int
    {
        $now = CarbonImmutable::now();
        $orders = Order::query()
            ->whereIn('status', [OrderStatus::Accepted, OrderStatus::Preparing])
            ->whereNotNull('accepted_at')
            ->whereNotNull('estimated_preparation_minutes')
            ->get();

        $created = 0;

        foreach ($orders as $order) {
            $expectedEnd = $order->accepted_at?->copy()->addMinutes((int) $order->estimated_preparation_minutes);

            if (! $expectedEnd || $now->lte($expectedEnd->addMinutes(15))) {
                continue;
            }

            $created += $this->createAlertIfMissing(
                bookingId: $order->id,
                alertType: AlertType::OverdueCompletion,
                severity: AlertSeverity::High,
                payload: [
                    'order_number' => $order->order_number,
                    'expected_end_at' => $expectedEnd->toDateTimeString(),
                    'overdue_minutes' => $expectedEnd->diffInMinutes($now),
                ],
            );
        }

        return $created;
    }

    private function createAlertIfMissing(int $bookingId, AlertType $alertType, AlertSeverity $severity, array $payload): int
    {
        $exists = SystemAlert::query()
            ->where('booking_id', $bookingId)
            ->where('booking_type', Order::class)
            ->where('alert_type', $alertType->value)
            ->whereIn('status', [SystemAlertStatus::New->value, SystemAlertStatus::Acknowledged->value])
            ->exists();

        if ($exists) {
            return 0;
        }

        SystemAlert::query()->create([
            'booking_id' => $bookingId,
            'booking_type' => Order::class,
            'alert_type' => $alertType->value,
            'severity' => $severity->value,
            'status' => SystemAlertStatus::New->value,
            'payload' => $payload,
        ]);

        return 1;
    }
}
