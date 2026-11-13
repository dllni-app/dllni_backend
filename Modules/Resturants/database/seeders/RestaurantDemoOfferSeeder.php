<?php

declare(strict_types=1);

namespace Modules\Resturants\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\User\Services\RestaurantFoodEligibility;

final class RestaurantDemoOfferSeeder extends Seeder
{
    public function run(?int $restaurantId = null): void
    {
        if (app()->environment('production')) {
            throw new \LogicException('Demo offer repair is not for production.');
        }

        $restaurants = Restaurant::query()->whereIn('slug', [
            'la-piazza-italian', 'golden-dragon-asian', 'burger-haven',
            'beit-al-sham-grill', 'cedar-breakfast-house',
        ])->when($restaurantId !== null, fn ($q) => $q->whereKey($restaurantId))->get();

        foreach ($restaurants as $restaurant) {
            DB::transaction(function () use ($restaurant): void {
                $base = RestaurantFoodEligibility::browsableProducts(Product::query())
                    ->where('restaurant_id', $restaurant->id)
                    ->where('classification_status', 'reviewed')
                    ->whereIn('product_kind', ['main_dish', 'combo_meal'])
                    ->orderByDesc('is_featured')->orderBy('id');
                $meals = (clone $base)->limit(3)->pluck('id')->all();
                $weekend = (clone $base)->whereNotIn('id', $meals)->limit(3)->pluck('id')->all();
                if ($weekend === []) {
                    $weekend = $meals;
                }
                $definitions = [
                    'عرض الوجبات العائلية' => [$meals, 'خصم 15% على الأطباق الرئيسية والوجبات المختارة أدناه.'],
                    'عرض نهاية الأسبوع' => [$weekend, 'خصم 20% على الأطباق المختارة أدناه.'],
                ];
                foreach ($definitions as $name => [$ids, $description]) {
                    $offer = Offer::query()->where('restaurant_id', $restaurant->id)
                        ->where('name', $name)->where('offer_type', 'product_discount')->first();
                    if ($offer === null) {
                        continue;
                    }
                    $changes = $offer->products()->sync($ids);
                    $offer->description = $description;
                    if ($ids === []) {
                        $offer->is_active = false;
                    }
                    if ($offer->isDirty() || $changes['attached'] || $changes['detached'] || $changes['updated']) {
                        $offer->rule_version = (int) ($offer->rule_version ?? 1) + 1;
                        $offer->save();
                    }
                }
            });
        }
    }
}
