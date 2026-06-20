<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Request;
use Modules\Resturants\Http\Resources\OrderResource;
use Modules\Resturants\Models\Order;

it('includes customer phone when user relation is loaded', function (): void {
    $user = new User([
        'id' => 10,
        'name' => 'Customer',
        'email' => 'customer@example.test',
        'phone' => '+963944100002',
    ]);

    $order = new Order();
    $order->id = 1;
    $order->user_id = 10;
    $order->restaurant_id = 5;
    $order->order_number = 'RST-1';
    $order->status = 'pending';
    $order->created_at = now();
    $order->updated_at = now();
    $order->setRelation('user', $user);

    $payload = (new OrderResource($order))->toArray(Request::create('/'));

    expect($payload['user']['phone'])->toBe('+963944100002');
});
