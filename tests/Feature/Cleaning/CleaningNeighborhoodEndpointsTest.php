<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningNeighborhood;

it('lists active neighborhoods and filters by search text', function (): void {
    Sanctum::actingAs(User::factory()->create());

    CleaningNeighborhood::factory()->create([
        'city_name' => 'Aleppo',
        'name_ar' => 'Bustan al-Pasha',
        'name_en' => 'Bustan al-Pasha',
        'aliases' => ['Bustan al-Pasha district'],
        'is_active' => true,
    ]);
    CleaningNeighborhood::factory()->create([
        'city_name' => 'Aleppo',
        'name_ar' => 'Jamiliyah',
        'name_en' => 'Jamiliyah',
        'aliases' => ['Al-Jamiliyah'],
        'is_active' => true,
    ]);
    CleaningNeighborhood::factory()->create([
        'city_name' => 'Aleppo',
        'name_ar' => 'Hidden Neighborhood',
        'is_active' => false,
    ]);

    $response = $this->getJson('/api/v1/cleaning/neighborhoods?search=bustan');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.nameAr', 'Bustan al-Pasha')
        ->assertJsonPath('data.0.isActive', true);
});

it('matches neighborhood aliases through the match endpoint', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $neighborhood = CleaningNeighborhood::factory()->create([
        'city_name' => 'Aleppo',
        'name_ar' => 'Bustan al-Pasha',
        'name_en' => 'Bustan al-Pasha',
        'aliases' => ['Bustan al-Pasha district', 'Bustan Pasha'],
    ]);

    $response = $this->postJson('/api/v1/cleaning/neighborhoods/match', [
        'text' => 'Bustan al-Pasha district',
        'city' => 'Aleppo',
    ]);

    $response->assertOk()
        ->assertJsonPath('matched', true)
        ->assertJsonPath('data.id', $neighborhood->id)
        ->assertJsonPath('data.nameAr', 'Bustan al-Pasha');
});
