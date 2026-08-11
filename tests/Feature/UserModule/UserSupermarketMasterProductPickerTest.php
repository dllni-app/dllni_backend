<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\MasterProductFactory;
use Laravel\Sanctum\Sanctum;

it('lists all active master products when the picker search index is omitted', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    MasterProductFactory::new()->create([
        'name' => 'Apple',
        'is_active' => true,
    ]);
    MasterProductFactory::new()->create([
        'name' => 'Banana',
        'is_active' => true,
    ]);
    MasterProductFactory::new()->create([
        'name' => 'Hidden product',
        'is_active' => false,
    ]);

    $response = $this->getJson('/api/v1/user/supermarket/master-products/search?perPage=50&page=1');

    $response->assertOk()
        ->assertJsonPath('meta.current_page', 1);

    $names = collect($response->json('data'))->pluck('name')->all();

    expect($names)
        ->toContain('Apple')
        ->toContain('Banana')
        ->not->toContain('Hidden product');
});
