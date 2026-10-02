<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('products')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->whereNull('products.item_type')
            ->select([
                'products.id',
                'products.name',
                'products.description',
                'categories.name as category_name',
            ])
            ->orderBy('products.id')
            ->get();

        foreach ($rows as $row) {
            $type = $this->classify(
                is_string($row->category_name ?? null) ? $row->category_name : null,
                is_string($row->name ?? null) ? $row->name : null,
                is_string($row->description ?? null) ? $row->description : null,
            );

            if ($type === 'other') {
                continue;
            }

            DB::table('products')
                ->where('id', $row->id)
                ->whereNull('item_type')
                ->update(['item_type' => $type]);
        }
    }

    public function down(): void
    {
        // Data backfill is intentionally preserved on rollback.
    }

    private function classify(?string $category, ?string $name, ?string $description): string
    {
        $text = $this->normalize(implode(' ', array_filter([$category, $name, $description])));

        $aliases = [
            'sandwich' => ['سندويش', 'ساندويش', 'سندويتش', 'sandwich'],
            'burger' => ['برغر', 'برجر', 'burger'],
            'pizza' => ['بيتزا', 'pizza'],
            'drink' => ['مشروب', 'مشروبات', 'عصير', 'كولا', 'بيبسي', 'drink', 'beverage'],
            'dessert' => ['حلويات', 'حلو', 'كيك', 'dessert', 'sweet'],
            'salad' => ['سلطه', 'سلطات', 'salad'],
            'combo' => ['كومبو', 'combo'],
            'family_meal' => ['عائلي', 'عائليه', 'family meal'],
            'breakfast' => ['فطور', 'افطار', 'breakfast'],
            'appetizer' => ['مقبلات', 'مقبل', 'appetizer', 'starter'],
            'side' => ['جانبي', 'جانبيه', 'سايد', 'side dish'],
            'meal' => [
                'وجبه', 'وجبات', 'طبق رئيسي', 'الطبق الرئيسي',
                'اطباق رئيسيه', 'الاطباق الرئيسيه',
                'main course', 'main dish', 'meal', 'entree',
            ],
        ];

        foreach ($aliases as $type => $words) {
            foreach ($words as $word) {
                if (str_contains($text, $this->normalize($word))) {
                    return $type;
                }
            }
        }

        return 'other';
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(mb_trim($value));
        $value = str_replace(['أ', 'إ', 'آ', 'ى', 'ة', 'ـ'], ['ا', 'ا', 'ا', 'ي', 'ه', ''], $value);
        $value = preg_replace('/[ًٌٍَُِّْـ]/u', '', $value) ?? $value;
        $value = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_trim($value);
    }
};
