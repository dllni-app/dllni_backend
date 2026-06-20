<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Restaurant;

it('allows restaurant owner to update profile without sending user id or slug', function (): void {
    $owner = User::factory()->create([
        'module_type' => UserModuleType::RestaurantSeller,
    ]);

    $restaurant = Restaurant::factory()->create([
        'user_id' => $owner->id,
        'name' => 'Old Name',
        'slug' => 'old-name',
    ]);

    Sanctum::actingAs($owner);

    $this->putJson('/api/v1/restaurant-owner/restaurant', [
        'name' => 'Updated Restaurant',
        'description' => 'Updated description',
        'address' => 'Updated address',
        'city' => 'Damascus',
        'district' => 'Al-Midan',
        'phone' => '+963944100002',
    ])->assertOk()->assertJsonPath('data.name', 'Updated Restaurant');

    $this->assertDatabaseHas('restaurants', [
        'id' => $restaurant->id,
        'user_id' => $owner->id,
        'name' => 'Updated Restaurant',
    ]);
});
