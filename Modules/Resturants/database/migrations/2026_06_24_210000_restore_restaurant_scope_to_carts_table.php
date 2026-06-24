<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('carts', 'restaurant_id')) {
            Schema::table('carts', function (Blueprint $table): void {
                $table->foreignId('restaurant_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('restaurants')
                    ->cascadeOnDelete();
            });
        }

        $this->dropUserOnlyUniqueIndexIfPresent();
        $this->splitExistingCartsByRestaurant();
        $this->deleteEmptyOrUnscopedCarts();

        Schema::table('carts', function (Blueprint $table): void {
            $table->unique(['user_id', 'restaurant_id'], 'carts_user_restaurant_unique');
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->dropUnique('carts_user_restaurant_unique');
        });

        $this->mergeCartsBackToOnePerUser();

        Schema::table('carts', function (Blueprint $table): void {
            $table->unique('user_id');
        });

        if (Schema::hasColumn('carts', 'restaurant_id')) {
            Schema::table('carts', function (Blueprint $table): void {
                $table->dropForeign(['restaurant_id']);
                $table->dropColumn('restaurant_id');
            });
        }
    }

    private function dropUserOnlyUniqueIndexIfPresent(): void
    {
        try {
            Schema::table('carts', function (Blueprint $table): void {
                $table->dropUnique(['user_id']);
            });
        } catch (Throwable) {
            // Some environments may already have the new merchant-scoped index only.
        }
    }

    private function splitExistingCartsByRestaurant(): void
    {
        $carts = DB::table('carts')
            ->orderBy('id')
            ->get(['id', 'user_id', 'restaurant_id', 'created_at', 'updated_at']);

        foreach ($carts as $cart) {
            $itemsByRestaurant = DB::table('cart_items')
                ->join('products', 'products.id', '=', 'cart_items.product_id')
                ->where('cart_items.cart_id', $cart->id)
                ->whereNotNull('products.restaurant_id')
                ->select('cart_items.id as item_id', 'products.restaurant_id')
                ->get()
                ->groupBy('restaurant_id');

            if ($itemsByRestaurant->isEmpty()) {
                continue;
            }

            $firstRestaurantId = (int) $itemsByRestaurant->keys()->first();

            DB::table('carts')
                ->where('id', $cart->id)
                ->update([
                    'restaurant_id' => $firstRestaurantId,
                    'updated_at' => now(),
                ]);

            foreach ($itemsByRestaurant as $restaurantId => $items) {
                $restaurantId = (int) $restaurantId;

                if ($restaurantId === $firstRestaurantId) {
                    continue;
                }

                $targetCartId = DB::table('carts')
                    ->where('user_id', $cart->user_id)
                    ->where('restaurant_id', $restaurantId)
                    ->value('id');

                if (! $targetCartId) {
                    $targetCartId = DB::table('carts')->insertGetId([
                        'user_id' => $cart->user_id,
                        'restaurant_id' => $restaurantId,
                        'created_at' => $cart->created_at ?? now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('cart_items')
                    ->whereIn('id', $items->pluck('item_id')->all())
                    ->update([
                        'cart_id' => $targetCartId,
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    private function deleteEmptyOrUnscopedCarts(): void
    {
        DB::table('cart_items')
            ->leftJoin('products', 'products.id', '=', 'cart_items.product_id')
            ->whereNull('products.restaurant_id')
            ->delete();

        $emptyOrUnscopedCartIds = DB::table('carts')
            ->leftJoin('cart_items', 'cart_items.cart_id', '=', 'carts.id')
            ->whereNull('cart_items.id')
            ->orWhereNull('carts.restaurant_id')
            ->pluck('carts.id')
            ->unique()
            ->all();

        if ($emptyOrUnscopedCartIds !== []) {
            DB::table('carts')
                ->whereIn('id', $emptyOrUnscopedCartIds)
                ->delete();
        }
    }

    private function mergeCartsBackToOnePerUser(): void
    {
        $cartGroups = DB::table('carts')
            ->orderBy('id')
            ->get(['id', 'user_id'])
            ->groupBy('user_id');

        foreach ($cartGroups as $carts) {
            $keeper = $carts->first();
            $duplicates = $carts->slice(1);

            if ($duplicates->isEmpty()) {
                continue;
            }

            DB::table('cart_items')
                ->whereIn('cart_id', $duplicates->pluck('id')->all())
                ->update(['cart_id' => $keeper->id]);

            DB::table('carts')
                ->whereIn('id', $duplicates->pluck('id')->all())
                ->delete();
        }
    }
};
