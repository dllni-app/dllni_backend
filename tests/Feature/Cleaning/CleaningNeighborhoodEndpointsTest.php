<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Database\Seeders\AleppoNeighborhoodSeeder;
use Modules\Cleaning\Models\CleaningNeighborhood;
use Modules\Cleaning\Support\CleaningNeighborhoodNameNormalizer;

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

it('normalizes Arabic neighborhood names like Flutter work areas', function (): void {
    expect(CleaningNeighborhoodNameNormalizer::normalize('حي الأشرفية'))->toBe('الاشرفية')
        ->and(CleaningNeighborhoodNameNormalizer::normalize('حي الإنذارات'))->toBe('الانذارات')
        ->and(CleaningNeighborhoodNameNormalizer::normalize('الراموسة'))->toBe('الراموسة')
        ->and(CleaningNeighborhoodNameNormalizer::normalize('حي حلب الجديدة'))->toBe('حلب الجديدة');
});

it('seeds Arabic aliases that match mobile work area normalization', function (): void {
    (new AleppoNeighborhoodSeeder())->run();
    (new AleppoNeighborhoodSeeder())->run();

    $neighborhood = CleaningNeighborhood::query()
        ->where('name_ar', 'الأشرفية')
        ->firstOrFail();

    expect($neighborhood->normalized_name)->toBe('الاشرفية')
        ->and($neighborhood->aliases)->toContain('حي الأشرفية')
        ->and($neighborhood->aliases)->toContain('الاشرفية')
        ->and(CleaningNeighborhood::query()->where('name_ar', 'الأشرفية')->count())->toBe(1);
});
