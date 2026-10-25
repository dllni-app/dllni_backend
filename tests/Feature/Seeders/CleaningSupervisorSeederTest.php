<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\CleaningSupervisorSeeder;
use Illuminate\Support\Facades\Hash;

it('seeds a verified active supervisor who can sign in to the supervisor app', function (): void {
    $this->seed(CleaningSupervisorSeeder::class);

    $supervisor = User::query()->where('email', CleaningSupervisorSeeder::EMAIL)->firstOrFail();

    expect($supervisor->phone)->toBe(CleaningSupervisorSeeder::PHONE)
        ->and($supervisor->is_active)->toBeTrue()
        ->and($supervisor->phone_verified_at)->not->toBeNull()
        ->and($supervisor->hasRole('Cleaning Supervisor'))->toBeTrue()
        ->and(Hash::check('password', $supervisor->password))->toBeTrue();

    $this->postJson('/api/v1/supervisor/login', [
        'phone' => CleaningSupervisorSeeder::PHONE,
        'password' => 'password',
    ])->assertOk()->assertJsonPath('data.user.id', $supervisor->id);
});

it('is idempotent and preserves an existing supervisor password', function (): void {
    $this->seed(CleaningSupervisorSeeder::class);
    $supervisor = User::query()->where('email', CleaningSupervisorSeeder::EMAIL)->firstOrFail();
    $supervisor->forceFill(['password' => 'updated-for-test'])->save();

    $this->seed(CleaningSupervisorSeeder::class);

    expect(User::query()->where('email', CleaningSupervisorSeeder::EMAIL)->count())->toBe(1)
        ->and(Hash::check('updated-for-test', $supervisor->fresh()->password))->toBeTrue();
});

it('does not create the demo supervisor in production', function (): void {
    config(['app.env' => 'production']);

    try {
        $this->seed(CleaningSupervisorSeeder::class);
        expect(User::query()->where('email', CleaningSupervisorSeeder::EMAIL)->exists())->toBeFalse();
    } finally {
        config(['app.env' => 'testing']);
    }
});
