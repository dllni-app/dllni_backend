<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\SystemAlertStatus;
use App\Models\SystemAlert;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Models\DeliveryOrder;

final class DeliverySystemAlertGenerator
{
    public function handle(): int
    {
        return $this->generateDispatchExhaustedAlerts()
            + $this->generateStaleDriverLocationAlerts();
    }

    private function generateDispatchExhaustedAlerts(): int
    {
        $orders=DeliveryOrder::query()
            ->where('status',DeliveryOrderStatus::Stopped->value)
            ->get();

        $created=0;
        foreach($orders as $order){
            $created += $this->createIfMissing(
                $order,
                AlertType::DeliveryDispatchExhausted,
                AlertSeverity::High,
                [
                    'message'=>'Delivery dispatch stopped before a driver was assigned.',
                    'order_number'=>$order->order_number,
                    'stop_reason'=>$order->stop_reason,
                    'dispatch_wave'=>$order->dispatch_wave,
                ]
            );
        }

        return $created;
    }

    private function generateStaleDriverLocationAlerts(): int
    {
        $cutoff=now()->subMinutes(15);
        $orders=DeliveryOrder::query()
            ->whereIn('status',[
                DeliveryOrderStatus::Accepted->value,
                DeliveryOrderStatus::InProgress->value,
                DeliveryOrderStatus::PickedUp->value,
            ])
            ->whereNotNull('driver_id')
            ->whereHas('driver',fn($query)=>$query
                ->whereNull('last_seen_at')
                ->orWhere('last_seen_at','<=',$cutoff))
            ->with('driver')
            ->get();

        $created=0;
        foreach($orders as $order){
            $created += $this->createIfMissing(
                $order,
                AlertType::StaleDriverLocation,
                AlertSeverity::High,
                [
                    'message'=>'Assigned driver location/heartbeat has not been updated for more than 15 minutes.',
                    'order_number'=>$order->order_number,
                    'driver_id'=>$order->driver_id,
                    'driver_last_seen_at'=>$order->driver?->last_seen_at?->toDateTimeString(),
                ]
            );
        }

        return $created;
    }

    private function createIfMissing(DeliveryOrder $order, AlertType $type, AlertSeverity $severity, array $payload): int
    {
        $exists=SystemAlert::query()
            ->where('booking_type','delivery_order')
            ->where('booking_id',$order->id)
            ->where('alert_type',$type->value)
            ->whereIn('status',[SystemAlertStatus::New->value,SystemAlertStatus::Acknowledged->value])
            ->exists();

        if($exists){return 0;}

        SystemAlert::query()->create([
            'booking_type'=>'delivery_order',
            'booking_id'=>$order->id,
            'alert_type'=>$type->value,
            'severity'=>$severity->value,
            'status'=>SystemAlertStatus::New->value,
            'payload'=>$payload,
        ]);

        return 1;
    }
}
