<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Models\PlatformCoupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UserCouponsIndexController
{
    public function __invoke(Request $request): JsonResponse
    {
        $section = $this->canonicalSection($request->query('section'));
        $userId = (int) $request->user()->id;
        $locale = app()->getLocale();

        $coupons = PlatformCoupon::query()
            ->with('constraints')
            ->currentlyActive()
            ->forUser($userId)
            ->when($section !== null, fn ($query) => $query->whereIn('section', [$section, PlatformCoupon::SECTION_ALL]))
            ->where(function ($query) use ($userId): void {
                $query->whereNull('per_user_usage_limit')
                    ->orWhereRaw(
                        '(select count(*) from platform_coupon_redemptions where platform_coupon_redemptions.platform_coupon_id = platform_coupons.id and platform_coupon_redemptions.user_id = ?) < platform_coupons.per_user_usage_limit',
                        [$userId]
                    );
            })
            ->orderByRaw('expires_at is null')
            ->orderBy('expires_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (PlatformCoupon $coupon) use ($locale): array {
                $constraints = $coupon->constraints->groupBy('constraint_type');

                return [
                    'id' => $coupon->id,
                    'code' => $coupon->code,
                    'title' => $coupon->localizedTitle($locale),
                    'description' => $coupon->localizedDescription($locale),
                    'section' => $coupon->section,
                    'discount' => [
                        'type' => $coupon->discount_type,
                        'value' => (float) $coupon->discount_value,
                        'maxAmount' => $coupon->max_discount_amount !== null ? (float) $coupon->max_discount_amount : null,
                    ],
                    'minOrderAmount' => $coupon->min_order_amount !== null ? (float) $coupon->min_order_amount : null,
                    'startsAt' => $coupon->starts_at?->toIso8601String(),
                    'expiresAt' => $coupon->expires_at?->toIso8601String(),
                    'appliesTo' => [
                        'propertyTypes' => $constraints->get('property_type')?->pluck('constraint_value')->values()->all() ?? [],
                        'cleaningModes' => $constraints->get('cleaning_mode')?->pluck('constraint_value')->values()->all() ?? [],
                        'eventTypes' => $constraints->get('event_type')?->pluck('constraint_value')->values()->all() ?? [],
                    ],
                ];
            })
            ->values();

        return response()->json(['coupons' => $coupons]);
    }

    private function canonicalSection(mixed $section): ?string
    {
        return match ($section) {
            null, '', 'all' => null,
            'restaurants', 'restaurant' => PlatformCoupon::SECTION_RESTAURANT,
            'supermarket' => PlatformCoupon::SECTION_SUPERMARKET,
            'cleaning' => PlatformCoupon::SECTION_CLEANING,
            default => abort(422, 'Invalid coupon section.'),
        };
    }
}
