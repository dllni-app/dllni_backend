<?php

declare(strict_types=1);

use Modules\Cleaning\Models\CleaningBanner;

it('returns only visible cleaning banners ordered by sort order', function (): void {
    CleaningBanner::factory()->create([
        'title' => 'Later banner',
        'sort_order' => 20,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDays(10),
        'is_active' => true,
    ]);

    CleaningBanner::factory()->create([
        'title' => 'Inactive banner',
        'sort_order' => 1,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(10),
        'is_active' => false,
    ]);

    $visibleA = CleaningBanner::factory()->create([
        'title' => 'First visible banner',
        'sort_order' => 2,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(10),
        'is_active' => true,
    ]);

    $visibleB = CleaningBanner::factory()->create([
        'title' => 'Second visible banner',
        'sort_order' => 5,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(10),
        'is_active' => true,
    ]);

    $this->getJson('/api/v1/user/cleaning/home/banners')
        ->assertOk()
        ->assertJsonCount(2, 'banners')
        ->assertJsonPath('banners.0.id', $visibleA->id)
        ->assertJsonPath('banners.0.title', 'First visible banner')
        ->assertJsonPath('banners.1.id', $visibleB->id)
        ->assertJsonPath('banners.1.title', 'Second visible banner');
});

