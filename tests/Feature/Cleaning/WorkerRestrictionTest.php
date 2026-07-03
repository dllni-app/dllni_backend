<?php

declare(strict_types=1);

use App\Models\Worker;

it('treats deactivated, suspended and financially blocked workers as restricted', function (): void {
    $active = Worker::factory()->create([
        'is_active' => true,
        'is_suspended' => false,
        'security_deposit_status' => 'active',
    ]);
    $deactivated = Worker::factory()->create([
        'is_active' => false,
        'is_suspended' => false,
        'security_deposit_status' => 'active',
    ]);
    $suspended = Worker::factory()->create([
        'is_active' => true,
        'is_suspended' => true,
        'security_deposit_status' => 'active',
    ]);
    $blocked = Worker::factory()->create([
        'is_active' => true,
        'is_suspended' => false,
        'security_deposit_status' => 'insufficient_balance',
    ]);

    $restrictedIds = Worker::query()->restricted()->pluck('id');
    $activeIds = Worker::query()->activeAvailable()->pluck('id');

    expect($restrictedIds)->toContain($deactivated->id, $suspended->id, $blocked->id)
        ->not->toContain($active->id);

    expect($activeIds)->toContain($active->id)
        ->not->toContain($deactivated->id, $suspended->id, $blocked->id);
});

it('splits every worker into exactly one of active or restricted', function (): void {
    Worker::factory()->count(3)->create(['is_active' => true, 'is_suspended' => false, 'security_deposit_status' => 'active']);
    Worker::factory()->count(2)->create(['is_active' => false]);

    $total = Worker::query()->count();
    $active = Worker::query()->activeAvailable()->count();
    $restricted = Worker::query()->restricted()->count();

    expect($active + $restricted)->toBe($total)
        ->and($active)->toBe(3)
        ->and($restricted)->toBe(2);
});
