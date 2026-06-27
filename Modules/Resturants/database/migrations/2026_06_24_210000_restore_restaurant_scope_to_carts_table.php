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

        $this->ensureUserIdIndexExists();
        $this->dropUserOnlyUniqueIndexIfPresent();
        $this->splitExistingCartsByRestaurant();
        $this->deleteEmptyOrUnscopedCarts();
        $this->createUserRestaurantUniqueIndexIfMissing();
    }

    public function down(): void
    {
        $this->dropIndexIfExists('carts_user_restaurant_unique');

        $this->mergeCartsBackToOnePerUser();

        if (! $this->indexExists('carts_user_id_unique')) {
            Schema::table('carts', function (Blueprint $table): void {
                $table->unique('user_id');
            });
        }

        if (Schema::hasColumn('carts', 'restaurant_id')) {
            Schema::table('carts', function (Blueprint $table): void {
                $table->dropForeign(['restaurant_id']);
                $table->dropColumn('restaurant_id');
            });
        }
    }

    private function dropUserOnlyUniqueIndexIfPresent(): void
    {
        foreach ($this->userOnlyUniqueIndexes() as $indexName) {
            $this->dropIndexIfExists($indexName);
        }
    }

    private function createUserRestaurantUniqueIndexIfMissing(): void
    {
        if ($this->indexExists('carts_user_restaurant_unique')) {
            return;
        }

        Schema::table('carts', function (Blueprint $table): void {
            $table->unique(['user_id', 'restaurant_id'], 'carts_user_restaurant_unique');
        });
    }

    private function ensureUserIdIndexExists(): void
    {
        if ($this->indexExists('carts_user_id_index')) {
            return;
        }

        Schema::table('carts', function (Blueprint $table): void {
            $table->index('user_id', 'carts_user_id_index');
        });
    }

    /**
     * @return array<int, string>
     */
    private function userOnlyUniqueIndexes(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns_list
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'carts'
              AND non_unique = 0
              AND index_name <> 'PRIMARY'
            GROUP BY index_name
            HAVING columns_list = 'user_id'
        SQL);

        return array_map(static function (object $row): string {
            $indexName = $row->index_name ?? $row->INDEX_NAME ?? $row->Index_name ?? null;

            return $indexName ? (string) $indexName : '';
        }, $rows);
    }

    private function indexExists(string $indexName): bool
    {
        $exists = DB::selectOne(<<<'SQL'
            SELECT 1 AS exists_flag
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'carts'
              AND index_name = ?
            LIMIT 1
        SQL, [$indexName]);

        return $exists !== null;
    }

    private function dropIndexIfExists(string $indexName): void
    {
        if (! $this->indexExists($indexName)) {
            return;
        }

        $safeIndexName = str_replace('`', '``', $indexName);
        DB::statement("ALTER TABLE `carts` DROP INDEX `{$safeIndexName}`");
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