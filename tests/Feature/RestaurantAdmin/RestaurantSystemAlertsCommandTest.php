<?php

declare(strict_types=1);

use App\Models\SystemAlert;
use Database\Factories\OrderFactory;
use Modules\Resturants\Models\Order;

it('generates overdue completion alert for restaurant orders', function (): void {
    $order = OrderFactory::new()->create([
        'status' => 'preparing',
        'accepted_at' => now()->subMinutes(50),
        'estimated_preparation_minutes' => 10,
    ]);

    $this->artisan('restaurant:generate-system-alerts')->assertSuccessful();

    $exists = SystemAlert::query()
        ->where('booking_id', $order->id)
        ->where('booking_type', Order::class)
        ->where('alert_type', 'overdue_completion')
        ->exists();

    expect($exists)->toBeTrue();
});
