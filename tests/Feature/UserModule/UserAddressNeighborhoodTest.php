<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningNeighborhood;

it('stores the canonical neighborhood snapshot when neighborhood id is provided', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $neighborhood = CleaningNeighborhood::factory()->create([
        'name_ar' => 'Bustan al-Pasha',
        'name_en' => 'Bustan al-Pasha',
    ]);

    $response = $this->postJson('/api/v1/user/addresses', [
        'label' => 'Home',
        'mobile' => '0935555788',
        'city' => 'Aleppo',
        'neighborhoodId' => $neighborhood->id,
        'neighborhood' => 'Custom client text',
        'street' => 'Granada Street',
    ]);

    $response->assertCreated()
        ->assertJsonPath('address.neighborhoodId', $neighborhood->id)
        ->assertJsonPath('address.neighborhood', 'Bustan al-Pasha');

    $this->assertDatabaseHas('user_addresses', [
        'user_id' => $user->id,
        'neighborhood_id' => $neighborhood->id,
        'neighborhood' => 'Bustan al-Pasha',
    ]);
});
