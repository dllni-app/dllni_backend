<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Jobs\ConvertPreferredCleaningBookingToOpenJob;
use App\Models\User;
use Database\Factories\SmStoreFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        Str::createRandomStringsNormally();
        Str::createUuidsNormally();
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
        // A delayed preferred-worker fallback must never execute immediately on
        // the synchronous test queue. Tests that assert dispatch can still
        // inspect this specifically faked job.
        Queue::fake([ConvertPreferredCleaningBookingToOpenJob::class]);
        Sleep::fake();

        $this->freezeTime();
    })
    ->in('Feature', 'Unit');

expect()->extend('toBeOne', fn () => $this->toBe(1));

function actingAsSupermarketSeller(): object
{
    $user = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);
    Sanctum::actingAs($user);
    $store = SmStoreFactory::new()->create(['owner_user_id' => $user->id]);

    return (object) ['user' => $user, 'store' => $store];
}
