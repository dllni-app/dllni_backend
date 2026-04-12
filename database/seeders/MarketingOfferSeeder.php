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
                'title' => 'خصم الترحيب',
                'description' => 'وفر في أول طلب لك من المطاعم والسوبرماركت داخل حلب.',
                'discount_label' => 'خصم 15%',
                'promo_code' => 'AHLAN15',
                'theme' => MarketingOfferTheme::Orange,
                'sort_order' => 1,
            ],
            [
                'title' => 'عرض نهاية الأسبوع',
                'description' => 'عروض خاصة لوجبات العائلة خلال عطلة نهاية الأسبوع.',
                'discount_label' => 'خصم 20%',
                'promo_code' => 'WEEKEND20',
                'theme' => MarketingOfferTheme::Gold,
                'sort_order' => 2,
            ],
            [
                'title' => 'سوق الخضار الطازج',
                'description' => 'خصومات على الخضار والألبان الطازجة طوال الأسبوع.',
                'discount_label' => 'خصم 10%',
                'promo_code' => 'SOUQ10',
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
                    'https://images.unsplash.com/photo-1607082350899-7e105aa886ae?auto=format&fit=crop&w=1200&q=80',
                    "marketing-offer-{$seed}"
                );
            }
        }
    }
}
