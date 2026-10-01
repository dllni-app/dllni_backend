<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Policies\DeliveryDriverPolicy;
use Modules\Delivery\Policies\DeliveryOrderPolicy;

uses(RefreshDatabase::class);

it('lets platform admins inspect delivery records across companies without breaking company isolation', function (): void {
    $this->seed(DatabaseSeeder::class);

    $admin = User::query()->where('email', 'admin@admin.com')->firstOrFail();
    $primaryOwner = User::query()->where('email', 'delivery.owner@dllni.sy')->firstOrFail();
    $otherOwner = User::query()->where('email', 'delivery.owner.shahba@dllni.sy')->firstOrFail();

    $driver = DeliveryDriver::query()
        ->whereHas('company', fn ($query) => $query->where('owner_user_id', $primaryOwner->id))
        ->firstOrFail();

    $order = DeliveryOrder::query()
        ->whereHas('company', fn ($query) => $query->where('owner_user_id', $primaryOwner->id))
        ->firstOrFail();

    $driverPolicy = app(DeliveryDriverPolicy::class);
    $orderPolicy = app(DeliveryOrderPolicy::class);

    expect($driverPolicy->view($admin, $driver))->toBeTrue()
        ->and($driverPolicy->update($admin, $driver))->toBeTrue()
        ->and($orderPolicy->view($admin, $order))->toBeTrue()
        ->and($orderPolicy->update($admin, $order))->toBeTrue()
        ->and($driverPolicy->view($primaryOwner, $driver))->toBeTrue()
        ->and($orderPolicy->view($primaryOwner, $order))->toBeTrue()
        ->and($driverPolicy->view($otherOwner, $driver))->toBeFalse()
        ->and($orderPolicy->view($otherOwner, $order))->toBeFalse();
});
