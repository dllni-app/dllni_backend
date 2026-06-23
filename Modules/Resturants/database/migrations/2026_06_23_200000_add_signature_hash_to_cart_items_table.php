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
        if (! Schema::hasColumn('cart_items', 'signature_hash')) {
            Schema::table('cart_items', function (Blueprint $table): void {
                $table->string('signature_hash', 64)->nullable()->after('special_instructions');
            });
        }

        $this->backfillAndMergeDuplicateCartItems();

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->unique(['cart_id', 'signature_hash'], 'cart_items_cart_signature_unique');
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropUnique('cart_items_cart_signature_unique');
        });

        if (Schema::hasColumn('cart_items', 'signature_hash')) {
            Schema::table('cart_items', function (Blueprint $table): void {
                $table->dropColumn('signature_hash');
            });
        }
    }

    private function backfillAndMergeDuplicateCartItems(): void
    {
        $items = DB::table('cart_items')
            ->orderBy('cart_id')
            ->orderBy('id')
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        $modifierRows = DB::table('cart_item_modifier')
            ->whereIn('cart_item_id', $items->pluck('id')->all())
            ->orderBy('modifier_id')
            ->get()
            ->groupBy('cart_item_id');

        $keptByCartAndSignature = [];

        foreach ($items as $item) {
            $modifierIds = ($modifierRows[$item->id] ?? collect())
                ->pluck('modifier_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->sort()
                ->values()
                ->all();

            $signatureHash = $this->signatureHash(
                productId: (int) $item->product_id,
                modifierIds: $modifierIds,
                substituteProductId: $item->substitute_product_id === null ? null : (int) $item->substitute_product_id,
                note: $this->normalizeNote($item->special_instructions),
            );

            $groupKey = ((int) $item->cart_id).'|'.$signatureHash;

            if (! isset($keptByCartAndSignature[$groupKey])) {
                $keptByCartAndSignature[$groupKey] = $item;

                DB::table('cart_items')
                    ->where('id', $item->id)
                    ->update(['signature_hash' => $signatureHash]);

                continue;
            }

            $kept = $keptByCartAndSignature[$groupKey];
            $mergedQuantity = (int) $kept->quantity + (int) $item->quantity;
            $mergedTotal = (float) $kept->total_price + (float) $item->total_price;

            DB::table('cart_items')
                ->where('id', $kept->id)
                ->update([
                    'quantity' => $mergedQuantity,
                    'total_price' => $mergedTotal,
                    'signature_hash' => $signatureHash,
                    'updated_at' => now(),
                ]);

            DB::table('cart_item_modifier')
                ->where('cart_item_id', $item->id)
                ->delete();

            DB::table('cart_items')
                ->where('id', $item->id)
                ->delete();

            $kept->quantity = $mergedQuantity;
            $kept->total_price = $mergedTotal;
            $keptByCartAndSignature[$groupKey] = $kept;
        }
    }

    /**
     * @param  array<int>  $modifierIds
     */
    private function signatureHash(int $productId, array $modifierIds, ?int $substituteProductId, ?string $note): string
    {
        sort($modifierIds);

        return hash('sha256', json_encode([
            'product_id' => $productId,
            'modifier_ids' => array_values(array_unique($modifierIds)),
            'substitute_product_id' => $substituteProductId,
            'note' => $note,
        ], JSON_THROW_ON_ERROR));
    }

    private function normalizeNote(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($note));

        return $normalized === '' ? null : $normalized;
    }
};
