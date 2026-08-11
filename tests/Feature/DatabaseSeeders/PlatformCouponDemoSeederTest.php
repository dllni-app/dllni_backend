<?php

declare(strict_types=1);

use App\Models\PlatformCoupon;
use App\Models\PlatformCouponConstraint;
use App\Models\User;
use Database\Seeders\PlatformCouponDemoSeeder;

it('seeds rerunnable platform coupons for the user app test account', function (): void {
    $user = User::factory()->create([
        'phone' => '+963944000222',
    ]);

    $this->seed(PlatformCouponDemoSeeder::class);
    $this->seed(PlatformCouponDemoSeeder::class);

    expect(PlatformCoupon::query()->count())->toBe(6)
        ->and(PlatformCoupon::query()->pluck('code')->all())->toEqualCanonicalizing([
            'DLLNI15',
            'CLEAN20',
            'DEEP25',
            'EVENT10',
            'REST20',
            'MARKET15',
        ]);

    $targetedCoupons = PlatformCoupon::query()
        ->where('audience_type', PlatformCoupon::AUDIENCE_SPECIFIC_USERS)
        ->with('users')
        ->get();

    expect($targetedCoupons)->toHaveCount(5);

    foreach ($targetedCoupons as $coupon) {
        expect($coupon->users->modelKeys())->toBe([$user->id]);
    }

    $deepCoupon = PlatformCoupon::query()->where('code', 'DEEP25')->firstOrFail();
    expect(
        $deepCoupon->constraints()
            ->orderBy('constraint_type')
            ->orderBy('constraint_value')
            ->get(['constraint_type', 'constraint_value'])
            ->map(fn (PlatformCouponConstraint $constraint): array => [
                $constraint->constraint_type,
                $constraint->constraint_value,
            ])
            ->all()
    )->toEqualCanonicalizing([
        [PlatformCouponConstraint::TYPE_PROPERTY, 'apartment'],
        [PlatformCouponConstraint::TYPE_PROPERTY, 'villa'],
        [PlatformCouponConstraint::TYPE_CLEANING_MODE, 'deep'],
    ]);

    $eventCoupon = PlatformCoupon::query()->where('code', 'EVENT10')->firstOrFail();
    expect($eventCoupon->constraints()->pluck('constraint_value')->all())
        ->toEqualCanonicalizing([
            'event_assistance',
            'birthday',
            'family_dinner',
            'large_gathering',
        ]);
});
