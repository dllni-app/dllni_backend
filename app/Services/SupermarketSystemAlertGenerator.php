<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\SystemAlertStatus;
use App\Models\SystemAlert;
use Carbon\CarbonImmutable;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Models\SmOrder;

final class SupermarketSystemAlertGenerator
{
    public function handle(): int
    {
        return $this->generateStalledProgressAlerts()
            + $this->generateReadyPickupOverdueAlerts();
    }

    private function generateStalledProgressAlerts(): int
    {
        $stalledSince=CarbonImmutable::now()->subMinutes(20);
        $orders=SmOrder::query()
            ->whereIn('status',[SmOrderStatus::Accepted->value,SmOrderStatus::Preparing->value])
            ->where('updated_at','<=',$stalledSince)
            ->get();

        $created=0;
        foreach($orders as $order){
            $created += $this->createIfMissing(
                $order,
                AlertType::StalledProgress,
                AlertSeverity::High,
                [
                    'message'=>'Supermarket order progress has not changed for at least 20 minutes.',
                    'order_number'=>$order->order_number,
                    'last_status_update_at'=>$order->updated_at?->toDateTimeString(),
                ]
            );
        }

        return $created;
    }

    private function generateReadyPickupOverdueAlerts(): int
    {
        $cutoff=CarbonImmutable::now()->subMinutes(20);
        $orders=SmOrder::query()
            ->where('status',SmOrderStatus::ReadyForPickup->value)
            ->whereNull('picked_up_at')
            ->whereNotNull('ready_for_pickup_at')
            ->where('ready_for_pickup_at','<=',$cutoff)
            ->get();

        $created=0;
        foreach($orders as $order){
            $created += $this->createIfMissing(
                $order,
                AlertType::ReadyPickupOverdue,
                AlertSeverity::Medium,
                [
                    'message'=>'Supermarket order has been ready for pickup for more than 20 minutes.',
                    'order_number'=>$order->order_number,
                    'ready_for_pickup_at'=>$order->ready_for_pickup_at?->toDateTimeString(),
                ]
            );
        }

        return $created;
    }

    private function createIfMissing(SmOrder $order, AlertType $type, AlertSeverity $severity, array $payload): int
    {
        $exists=SystemAlert::query()
            ->where('booking_type','supermarket_order')
            ->where('booking_id',$order->id)
            ->where('alert_type',$type->value)
            ->whereIn('status',[SystemAlertStatus::New->value,SystemAlertStatus::Acknowledged->value])
            ->exists();

        if($exists){return 0;}

        SystemAlert::query()->create([
            'booking_type'=>'supermarket_order',
            'booking_id'=>$order->id,
            'alert_type'=>$type->value,
            'severity'=>$severity->value,
            'status'=>SystemAlertStatus::New->value,
            'payload'=>$payload,
        ]);

        return 1;
    }
}
