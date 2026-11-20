<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cleaning_room_types')) {
            return;
        }

        // Retain historical codes and their prices for existing bookings.
        // Only the new-customer catalog hides these two legacy choices.
        DB::table('cleaning_room_types')
            ->whereIn('code', ['bedroom', 'living_room'])
            ->update(['is_active' => false, 'updated_at' => now()]);

        $types = [
            [
                'code' => 'room',
                'name' => 'غرف',
                'icon_key' => 'room',
                'sort_order' => 1,
                'regular_description' => 'تنظيف الغرفة وأرضيتها والأسطح المكشوفة حسب الأعمال المختارة.',
                'deep_description' => 'تنظيف أعمق للغرفة والزوايا والحواف والأسطح الممكن الوصول إليها بأمان.',
            ],
            [
                'code' => 'garden',
                'name' => 'جنينة',
                'icon_key' => 'garden',
                'sort_order' => 20,
                'regular_description' => 'تنظيف وترتيب مساحة الجنينة وإزالة الأتربة والمخلفات السطحية ضمن النطاق المحدد.',
                'deep_description' => 'تنظيف أعمق للأرضيات والحواف والمساحات الممكن الوصول إليها، دون أعمال الزراعة المتخصصة.',
            ],
            [
                'code' => 'garage_back',
                'name' => 'ظهر كراج',
                'icon_key' => 'garage',
                'sort_order' => 21,
                'regular_description' => 'كنس وتنظيف منطقة ظهر الكراج والأسطح المتاحة.',
                'deep_description' => 'تنظيف الزوايا والحواف والأتربة المتراكمة في منطقة ظهر الكراج بأمان.',
            ],
        ];

        foreach ($types as $item) {
            if (DB::table('cleaning_room_types')->where('code', $item['code'])->exists()) {
                continue; // Never overwrite an administrator's own pricing.
            }

            DB::table('cleaning_room_types')->insert([
                ...$item,
                // No made-up price: the administrator sets separate rates for
                // الحوسة and التعزيل and enables each new type in Filament.
                'regular_hourly_rate' => 0,
                'deep_hourly_rate' => 0,
                'is_active' => false,
                'suggested_minutes_by_size' => json_encode([
                    'small' => ['regular' => 25, 'deep' => 50],
                    'medium' => ['regular' => 35, 'deep' => 70],
                    'large' => ['regular' => 45, 'deep' => 90],
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Preserve bookings and admin-configured pricing. Do not automatically
        // delete newly configured types or reactivate retired selections.
    }
};
