<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Factories\SmStoreFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Supermarket\Models\SmStore;
use Modules\Supermarket\Models\SmStoreHours;
use Modules\User\Http\Resources\UserSupermarketStoreResource;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

it('applies owner temporary closure both to public details and open-now filter', function (): void {
    $store = SmStoreFactory::new()->create([
        'is_active' => true,
        'is_temporarily_closed' => true,
        'suspension_until' => null,
    ]);
    SmStoreHours::query()->create([
        'store_id' => $store->id,
        'day_of_week' => 'saturday',
        'open_time' => '00:00:00',
        'close_time' => '23:59:59',
        'is_closed' => false,
    ]);
    $at = CarbonImmutable::parse('2026-11-14 12:00:00');
    Carbon::setTestNow($at);
    try {
        $store->load('storeHours');
        expect($store->currentlyOpen($at))->toBeFalse();
        $payload = (new UserSupermarketStoreResource($store))->toArray(Request::create('/api/v1/user/supermarket/stores/'.$store->id));
        expect($payload['isTemporarilyClosed'])->toBeTrue()
            ->and($payload['isOpenNow'])->toBeFalse();
        expect(SmStore::query()->openNow(true)->whereKey($store->id)->exists())->toBeFalse();
    } finally {
        Carbon::setTestNow();
    }
});

it('matches yesterday overnight hours after midnight and closes at end time', function (): void {
    $store = SmStoreFactory::new()->create([
        'is_active' => true, 'is_temporarily_closed' => false, 'suspension_until' => null,
    ]);
    SmStoreHours::query()->create([
        'store_id' => $store->id,
        'day_of_week' => 'friday',
        'open_time' => '18:00:00',
        'close_time' => '02:00:00',
        'is_closed' => false,
    ]);
    $store->load('storeHours');
    $during = CarbonImmutable::parse('2026-11-14 01:00:00');
    $after = CarbonImmutable::parse('2026-11-14 02:00:00');
    expect($store->currentlyOpen($during))->toBeTrue();
    expect($store->currentlyOpen($after))->toBeFalse();
    Carbon::setTestNow($during);
    try {
        expect(SmStore::query()->openNow(true)->whereKey($store->id)->exists())->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }
    Carbon::setTestNow($after);
    try {
        expect(SmStore::query()->openNow(true)->whereKey($store->id)->exists())->toBeFalse();
    } finally {
        Carbon::setTestNow();
    }
});
