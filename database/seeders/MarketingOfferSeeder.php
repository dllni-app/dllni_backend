<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Support\SeederMedia;
use Illuminate\Database\Seeder;
use Modules\User\Enums\MarketingOfferTheme;
use Modules\User\Models\MarketingOffer;

final class MarketingOfferSeeder extends Seeder
{
    public function run(): void
    {
        $offers = [
            [
                'title' => 'Welcome Discount',
                'description' => 'Save on your first order across restaurants and supermarkets.',
                'discount_label' => '15% OFF',
                'promo_code' => 'WELCOME15',
                'theme' => MarketingOfferTheme::Orange,
                'sort_order' => 1,
            ],
            [
                'title' => 'Weekend Feast',
                'description' => 'Special deals for family meals this weekend.',
                'discount_label' => '20% OFF',
                'promo_code' => 'FEAST20',
                'theme' => MarketingOfferTheme::Gold,
                'sort_order' => 2,
            ],
            [
                'title' => 'Fresh Market',
                'description' => 'Fresh produce and dairy discounts all week.',
                'discount_label' => '10% OFF',
                'promo_code' => 'FRESH10',
                'theme' => MarketingOfferTheme::Green,
                'sort_order' => 3,
            ],
        ];

        foreach ($offers as $index => $offer) {
            $model = MarketingOffer::updateOrCreate(
                ['title' => $offer['title']],
                [
                    'description' => $offer['description'],
                    'discount_label' => $offer['discount_label'],
                    'promo_code' => $offer['promo_code'],
                    'starts_at' => now()->subDays(2),
                    'ends_at' => now()->addDays(14),
                    'theme' => $offer['theme']->value,
                    'sort_order' => $offer['sort_order'],
                    'is_active' => true,
                ]
            );

            if ($model->getFirstMedia(MarketingOffer::IMAGE_COLLECTION) === null) {
                $seed = $index + 1;

                SeederMedia::ensureSingleMedia(
                    $model,
                    MarketingOffer::IMAGE_COLLECTION,
                    "https://picsum.photos/seed/marketing-offer-{$seed}/900/600",
                    "marketing-offer-{$seed}"
                );
            }
        }
    }
}
