<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PlatformCoupon;
use App\Models\PlatformCouponConstraint;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PlatformCouponDemoSeeder extends Seeder
{
    private const USER_PHONE = '+963944000222';

    public function run(): void
    {
        $user = User::query()->where('phone', self::USER_PHONE)->first();

        if (! $user) {
            throw new RuntimeException(
                sprintf('Cannot seed platform coupons: user %s was not found. Run VerifiedUserSeeder first.', self::USER_PHONE)
            );
        }

        DB::transaction(function () use ($user): void {
            foreach ($this->coupons() as $definition) {
                $constraints = $definition['constraints'];
                unset($definition['constraints']);

                $coupon = PlatformCoupon::query()->updateOrCreate(
                    ['code' => $definition['code']],
                    $definition,
                );

                if ($coupon->audience_type === PlatformCoupon::AUDIENCE_SPECIFIC_USERS) {
                    $coupon->users()->sync([
                        $user->id => ['created_at' => now()],
                    ]);
                } else {
                    $coupon->users()->detach();
                }

                $coupon->constraints()->delete();

                if ($constraints !== []) {
                    $coupon->constraints()->createMany($constraints);
                }
            }
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function coupons(): array
    {
        $startsAt = now()->subDay();
        $expiresAt = now()->addMonths(3)->endOfDay();

        return [
            [
                'code' => 'DLLNI15',
                'title_ar' => 'خصم دلني العام 15%',
                'title_en' => 'Dllni 15% Platform Discount',
                'description_ar' => 'خصم صالح على خدمات التنظيف والمطاعم والسوبرماركت.',
                'description_en' => 'Valid for cleaning, restaurant, and supermarket orders.',
                'section' => PlatformCoupon::SECTION_ALL,
                'discount_type' => PlatformCoupon::DISCOUNT_PERCENTAGE,
                'discount_value' => 15,
                'max_discount_amount' => 25000,
                'min_order_amount' => 10000,
                'audience_type' => PlatformCoupon::AUDIENCE_ALL_USERS,
                'total_usage_limit' => 500,
                'per_user_usage_limit' => 10,
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
                'is_active' => true,
                'constraints' => [],
            ],
            [
                'code' => 'CLEAN20',
                'title_ar' => 'خصم تنظيف 20%',
                'title_en' => '20% Cleaning Discount',
                'description_ar' => 'خصم مخصص لحساب الاختبار على جميع طلبات التنظيف.',
                'description_en' => 'A user-specific discount for all cleaning bookings.',
                'section' => PlatformCoupon::SECTION_CLEANING,
                'discount_type' => PlatformCoupon::DISCOUNT_PERCENTAGE,
                'discount_value' => 20,
                'max_discount_amount' => 30000,
                'min_order_amount' => 10000,
                'audience_type' => PlatformCoupon::AUDIENCE_SPECIFIC_USERS,
                'total_usage_limit' => 100,
                'per_user_usage_limit' => 10,
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
                'is_active' => true,
                'constraints' => [],
            ],
            [
                'code' => 'DEEP25',
                'title_ar' => 'خصم التنظيف العميق 25%',
                'title_en' => '25% Deep Cleaning Discount',
                'description_ar' => 'صالح للتنظيف العميق في الشقق والفلل.',
                'description_en' => 'Valid for deep cleaning in apartments and villas.',
                'section' => PlatformCoupon::SECTION_CLEANING,
                'discount_type' => PlatformCoupon::DISCOUNT_PERCENTAGE,
                'discount_value' => 25,
                'max_discount_amount' => 50000,
                'min_order_amount' => 15000,
                'audience_type' => PlatformCoupon::AUDIENCE_SPECIFIC_USERS,
                'total_usage_limit' => 100,
                'per_user_usage_limit' => 10,
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
                'is_active' => true,
                'constraints' => [
                    [
                        'constraint_type' => PlatformCouponConstraint::TYPE_PROPERTY,
                        'constraint_value' => 'apartment',
                    ],
                    [
                        'constraint_type' => PlatformCouponConstraint::TYPE_PROPERTY,
                        'constraint_value' => 'villa',
                    ],
                    [
                        'constraint_type' => PlatformCouponConstraint::TYPE_CLEANING_MODE,
                        'constraint_value' => 'deep',
                    ],
                ],
            ],
            [
                'code' => 'EVENT10',
                'title_ar' => 'خصم مناسبات 10,000 ل.س',
                'title_en' => '10,000 SYP Event Discount',
                'description_ar' => 'خصم لخدمات المساعدة في أعياد الميلاد والعزائم والتجمعات الكبيرة.',
                'description_en' => 'A fixed discount for supported event-assistance bookings.',
                'section' => PlatformCoupon::SECTION_CLEANING,
                'discount_type' => PlatformCoupon::DISCOUNT_FIXED,
                'discount_value' => 10000,
                'max_discount_amount' => null,
                'min_order_amount' => 30000,
                'audience_type' => PlatformCoupon::AUDIENCE_SPECIFIC_USERS,
                'total_usage_limit' => 100,
                'per_user_usage_limit' => 10,
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
                'is_active' => true,
                'constraints' => [
                    [
                        'constraint_type' => PlatformCouponConstraint::TYPE_PROPERTY,
                        'constraint_value' => 'event_assistance',
                    ],
                    [
                        'constraint_type' => PlatformCouponConstraint::TYPE_EVENT,
                        'constraint_value' => 'birthday',
                    ],
                    [
                        'constraint_type' => PlatformCouponConstraint::TYPE_EVENT,
                        'constraint_value' => 'family_dinner',
                    ],
                    [
                        'constraint_type' => PlatformCouponConstraint::TYPE_EVENT,
                        'constraint_value' => 'large_gathering',
                    ],
                ],
            ],
            [
                'code' => 'REST20',
                'title_ar' => 'خصم مطاعم 20%',
                'title_en' => '20% Restaurant Discount',
                'description_ar' => 'خصم مخصص لحساب الاختبار على طلبات المطاعم.',
                'description_en' => 'A user-specific restaurant order discount.',
                'section' => PlatformCoupon::SECTION_RESTAURANT,
                'discount_type' => PlatformCoupon::DISCOUNT_PERCENTAGE,
                'discount_value' => 20,
                'max_discount_amount' => 30000,
                'min_order_amount' => 15000,
                'audience_type' => PlatformCoupon::AUDIENCE_SPECIFIC_USERS,
                'total_usage_limit' => 100,
                'per_user_usage_limit' => 10,
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
                'is_active' => true,
                'constraints' => [],
            ],
            [
                'code' => 'MARKET15',
                'title_ar' => 'خصم سوبرماركت 15,000 ل.س',
                'title_en' => '15,000 SYP Supermarket Discount',
                'description_ar' => 'خصم ثابت مخصص لحساب الاختبار على طلبات السوبرماركت.',
                'description_en' => 'A fixed user-specific supermarket order discount.',
                'section' => PlatformCoupon::SECTION_SUPERMARKET,
                'discount_type' => PlatformCoupon::DISCOUNT_FIXED,
                'discount_value' => 15000,
                'max_discount_amount' => null,
                'min_order_amount' => 50000,
                'audience_type' => PlatformCoupon::AUDIENCE_SPECIFIC_USERS,
                'total_usage_limit' => 100,
                'per_user_usage_limit' => 10,
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
                'is_active' => true,
                'constraints' => [],
            ],
        ];
    }
}
