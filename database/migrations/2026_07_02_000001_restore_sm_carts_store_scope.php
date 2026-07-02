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
        if (! Schema::hasColumn('sm_carts', 'store_id')) {
            Schema::table('sm_carts', function (Blueprint $table): void {
                $table->unsignedBigInteger('store_id')->nullable()->after('user_id');
            });
        }

        $this->dropIndexIfExists('sm_carts', 'sm_carts_user_id_unique');
        $this->splitExistingCartsByStore();
        $this->mergeDuplicateUserStoreCarts();

        if (! $this->foreignKeyExists('sm_carts', 'sm_cart_store_fk')) {
            Schema::table('sm_carts', function (Blueprint $table): void {
                $table->foreign('store_id', 'sm_cart_store_fk')
                    ->references('id')
                    ->on('sm_stores')
                    ->cascadeOnDelete();
            });
        }

        if (! $this->indexExists('sm_carts', 'sm_cart_user_store_uniq')) {
            Schema::table('sm_carts', function (Blueprint $table): void {
                $table->unique(['user_id', 'store_id'], 'sm_cart_user_store_uniq');
            });
        }
    }

    public function down(): void
    {
        $this->mergeCartsPerUser();
        $this->dropIndexIfExists('sm_carts', 'sm_cart_user_store_uniq');
        $this->dropForeignIfExists('sm_carts', 'sm_cart_store_fk');

        if (Schema::hasColumn('sm_carts', 'store_id')) {
            Schema::table('sm_carts', function (Blueprint $table): void {
                $table->dropColumn('store_id');
            });
        }

        if (! $this->indexExists('sm_carts', 'sm_carts_user_id_unique')) {
            Schema::table('sm_carts', function (Blueprint $table): void {
                $table->unique('user_id');
            });
        }
    }

    private function splitExistingCartsByStore(): void
    {
        $carts = DB::table('sm_carts')
            ->select(['id', 'user_id', 'store_id'])
            ->orderBy('id')
            ->get();

        foreach ($carts as $cart) {
            $items = DB::table('sm_cart_items')
                ->join('sm_products', 'sm_cart_items.product_id', '=', 'sm_products.id')
                ->where('sm_cart_items.cart_id', $cart->id)
                ->select([
                    'sm_cart_items.id',
                    'sm_cart_items.product_id',
                    'sm_cart_items.quantity',
                    'sm_cart_items.unit_price',
                    'sm_products.store_id',
                ])
                ->orderBy('sm_cart_items.id')
                ->get();

            $itemsWithoutStore = $items->filter(fn ($item): bool => $item->store_id === null);
            foreach ($itemsWithoutStore as $item) {
                DB::table('sm_cart_items')->where('id', $item->id)->delete();
            }

            $itemsByStore = $items
                ->filter(fn ($item): bool => $item->store_id !== null)
                ->groupBy(fn ($item): int => (int) $item->store_id);

            if ($itemsByStore->isEmpty()) {
                DB::table('sm_carts')->where('id', $cart->id)->delete();
                continue;
            }

            $primaryStoreId = $cart->store_id !== null
                ? (int) $cart->store_id
                : (int) $itemsByStore->keys()->first();

            DB::table('sm_carts')
                ->where('id', $cart->id)
                ->update([
                    'store_id' => $primaryStoreId,
                    'updated_at' => now(),
                ]);

            foreach ($itemsByStore as $storeId => $storeItems) {
                $storeId = (int) $storeId;

                if ($storeId === $primaryStoreId) {
                    continue;
                }

                $targetCartId = $this->resolveCartId((int) $cart->user_id, $storeId, (int) $cart->id);

                foreach ($storeItems as $item) {
                    $this->moveOrMergeItem((int) $item->id, (int) $item->product_id, (int) $item->quantity, (float) $item->unit_price, $targetCartId);
                }
            }
        }
    }

    private function mergeDuplicateUserStoreCarts(): void
    {
        $duplicates = DB::table('sm_carts')
            ->select([
                'user_id',
                'store_id',
                DB::raw('MIN(id) as keeper_id'),
                DB::raw('COUNT(*) as carts_count'),
            ])
            ->whereNotNull('store_id')
            ->groupBy('user_id', 'store_id')
            ->having('carts_count', '>', 1)
            ->get();

        foreach ($duplicates as $duplicate) {
            $duplicateCartIds = DB::table('sm_carts')
                ->where('user_id', $duplicate->user_id)
                ->where('store_id', $duplicate->store_id)
                ->where('id', '<>', $duplicate->keeper_id)
                ->pluck('id');

            foreach ($duplicateCartIds as $cartId) {
                $this->moveCartItems((int) $cartId, (int) $duplicate->keeper_id);
                DB::table('sm_carts')->where('id', $cartId)->delete();
            }
        }
    }

    private function mergeCartsPerUser(): void
    {
        $duplicates = DB::table('sm_carts')
            ->select([
                'user_id',
                DB::raw('MIN(id) as keeper_id'),
                DB::raw('COUNT(*) as carts_count'),
            ])
            ->groupBy('user_id')
            ->having('carts_count', '>', 1)
            ->get();

        foreach ($duplicates as $duplicate) {
            $duplicateCartIds = DB::table('sm_carts')
                ->where('user_id', $duplicate->user_id)
                ->where('id', '<>', $duplicate->keeper_id)
                ->pluck('id');

            foreach ($duplicateCartIds as $cartId) {
                $this->moveCartItems((int) $cartId, (int) $duplicate->keeper_id);
                DB::table('sm_carts')->where('id', $cartId)->delete();
            }
        }
    }

    private function resolveCartId(int $userId, int $storeId, int $exceptCartId): int
    {
        $existingCartId = DB::table('sm_carts')
            ->where('user_id', $userId)
            ->where('store_id', $storeId)
            ->where('id', '<>', $exceptCartId)
            ->value('id');

        if ($existingCartId !== null) {
            return (int) $existingCartId;
        }

        return (int) DB::table('sm_carts')->insertGetId([
            'user_id' => $userId,
            'store_id' => $storeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function moveCartItems(int $sourceCartId, int $targetCartId): void
    {
        $items = DB::table('sm_cart_items')
            ->where('cart_id', $sourceCartId)
            ->get(['id', 'product_id', 'quantity', 'unit_price']);

        foreach ($items as $item) {
            $this->moveOrMergeItem((int) $item->id, (int) $item->product_id, (int) $item->quantity, (float) $item->unit_price, $targetCartId);
        }
    }

    private function moveOrMergeItem(int $itemId, int $productId, int $quantity, float $unitPrice, int $targetCartId): void
    {
        $existingItem = DB::table('sm_cart_items')
            ->where('cart_id', $targetCartId)
            ->where('product_id', $productId)
            ->first(['id', 'quantity']);

        if ($existingItem !== null) {
            DB::table('sm_cart_items')
                ->where('id', $existingItem->id)
                ->update([
                    'quantity' => (int) $existingItem->quantity + max(1, $quantity),
                    'unit_price' => $unitPrice,
                    'updated_at' => now(),
                ]);

            DB::table('sm_cart_items')->where('id', $itemId)->delete();

            return;
        }

        DB::table('sm_cart_items')
            ->where('id', $itemId)
            ->update([
                'cart_id' => $targetCartId,
                'updated_at' => now(),
            ]);
    }

    private function indexExists(string $table, string $index): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return collect(DB::select("PRAGMA index_list('{$table}')"))
                ->contains(fn ($row): bool => ($row->name ?? null) === $index);
        }

        return collect(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]))->isNotEmpty();
    }

    private function foreignKeyExists(string $table, string $foreignKey): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return false;
        }

        $database = DB::getDatabaseName();

        return collect(DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$database, $table, $foreignKey, 'FOREIGN KEY']
        ))->isNotEmpty();
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        if (! $this->indexExists($table, $index)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($index): void {
            $table->dropUnique($index);
        });
    }

    private function dropForeignIfExists(string $table, string $foreignKey): void
    {
        if (! $this->foreignKeyExists($table, $foreignKey)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($foreignKey): void {
            $table->dropForeign($foreignKey);
        });
    }
};
