<?php

declare(strict_types=1);

namespace Modules\Cleaning\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CleaningCatalogScenarioSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedMaterialCatalog();
        $this->seedSpecialServiceCatalog();
        $this->seedEventCatalog();
    }

    private function seedMaterialCatalog(): void
    {
        if (! Schema::hasTable('cleaning_material_units')) {
            return;
        }

        $units = [
            ['name' => 'ملليلتر', 'code' => 'ml', 'symbol' => 'مل'],
            ['name' => 'لتر', 'code' => 'liter', 'symbol' => 'ل'],
            ['name' => 'غرام', 'code' => 'gram', 'symbol' => 'غ'],
            ['name' => 'كيلوغرام', 'code' => 'kg', 'symbol' => 'كغ'],
            ['name' => 'قطعة', 'code' => 'piece', 'symbol' => 'قطعة'],
            ['name' => 'عبوة', 'code' => 'pack', 'symbol' => 'عبوة'],
        ];        foreach ($units as $unit) {
            DB::table('cleaning_material_units')->updateOrInsert(
                ['code' => $unit['code']],
                $unit + ['is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        $unitIds = DB::table('cleaning_material_units')->pluck('id', 'code');

        $types = [
            ['name' => 'منظف أرضيات', 'unit' => 'liter', 'price' => 18],
            ['name' => 'مطهر أسطح', 'unit' => 'liter', 'price' => 22],
            ['name' => 'منظف زجاج', 'unit' => 'liter', 'price' => 20],
            ['name' => 'مزيل دهون', 'unit' => 'liter', 'price' => 28],
            ['name' => 'مبيض كلور', 'unit' => 'liter', 'price' => 16],
            ['name' => 'أكياس نفايات', 'unit' => 'pack', 'price' => 12],
            ['name' => 'قماش مايكروفايبر', 'unit' => 'piece', 'price' => 8],
            ['name' => 'إسفنج تنظيف', 'unit' => 'piece', 'price' => 5],
            ['name' => 'قفازات تنظيف', 'unit' => 'pack', 'price' => 10],
        ];

        foreach ($types as $type) {
            DB::table('cleaning_material_types')->updateOrInsert(
                ['name' => $type['name']],
                [
                    'cleaning_material_unit_id' => $unitIds[$type['unit']],
                    'price_per_unit' => $type['price'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }        if (! Schema::hasTable('cleaning_materials')) {
            return;
        }

        $typeIds = DB::table('cleaning_material_types')->pluck('id', 'name');
        $materials = [
            ['فلاش منظف أرضيات ليمون 2 لتر', 'منظف أرضيات', 48, 10],
            ['ديتول مطهر أسطح 1 لتر', 'مطهر أسطح', 36, 8],
            ['منظف زجاج احترافي 750 مل', 'منظف زجاج', 42, 10],
            ['مزيل دهون للمطابخ 1 لتر', 'مزيل دهون', 28, 7],
            ['مبيض كلور 2 لتر', 'مبيض كلور', 30, 8],
            ['أكياس نفايات كبيرة - 20 كيس', 'أكياس نفايات', 60, 15],
            ['قماش مايكروفايبر أزرق', 'قماش مايكروفايبر', 80, 20],
            ['إسفنج مزدوج الاستخدام', 'إسفنج تنظيف', 100, 25],
            ['قفازات نيتريل - 50 زوج', 'قفازات تنظيف', 24, 6],
        ];

        foreach ($materials as [$name, $typeName, $stock, $threshold]) {
            DB::table('cleaning_materials')->updateOrInsert(
                ['name' => $name],
                [
                    'cleaning_material_type_id' => $typeIds[$typeName],
                    'stock_quantity' => $stock,
                    'low_stock_threshold' => $threshold,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        if (Schema::hasTable('cleaning_material_type_quantity_rules')) {
            $this->seedMaterialTypeRules($typeIds);
        }
    }    private function seedMaterialTypeRules($typeIds): void
    {
        $rules = [
            ['منظف أرضيات', 'living_room', 'medium', 'regular', 0.10],
            ['منظف أرضيات', 'living_room', 'large', 'regular', 0.18],
            ['مطهر أسطح', 'bathroom', null, 'regular', 0.08],
            ['منظف زجاج', 'living_room', null, 'regular', 0.05],
            ['مزيل دهون', 'kitchen', null, 'regular', 0.10],
            ['مبيض كلور', 'bathroom', null, 'deep', 0.12],
            ['أكياس نفايات', null, null, 'regular', 0.25],
            ['قماش مايكروفايبر', null, null, 'regular', 0.20],
            ['إسفنج تنظيف', 'kitchen', null, 'regular', 0.25],
            ['قفازات تنظيف', null, null, 'regular', 0.10],
        ];

        foreach ($rules as [$typeName, $roomType, $roomSize, $mode, $quantity]) {
            DB::table('cleaning_material_type_quantity_rules')->updateOrInsert(
                [
                    'cleaning_material_type_id' => $typeIds[$typeName],
                    'room_type' => $roomType,
                    'room_size' => $roomSize,
                    'cleaning_mode' => $mode,
                ],
                [
                    'quantity_per_room' => $quantity,
                    'is_active' => true,
                    'requires_admin_resolution' => false,
                    'migration_conflict' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }    private function seedSpecialServiceCatalog(): void
    {
        if (! Schema::hasTable('cleaning_special_service_categories')) {
            return;
        }

        $categories = [
            ['name' => 'تنظيف المطابخ والأجهزة', 'slug' => 'kitchen-appliances', 'sort_order' => 10],
            ['name' => 'المفروشات والسجاد', 'slug' => 'upholstery-carpets', 'sort_order' => 20],
            ['name' => 'الزجاج والواجهات', 'slug' => 'glass-facades', 'sort_order' => 30],
            ['name' => 'تنظيف ما بعد الصيانة', 'slug' => 'post-maintenance', 'sort_order' => 40],
        ];

        foreach ($categories as $category) {
            DB::table('cleaning_special_service_categories')->updateOrInsert(
                ['slug' => $category['slug']],
                $category + ['is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        $levels = [
            ['نظيف نسبياً', 'light', 1.00, 10],
            ['اتساخ عادي', 'normal', 1.15, 20],
            ['اتساخ شديد', 'heavy', 1.40, 30],
            ['اتساخ شديد جداً', 'very-heavy', 1.75, 40],
        ];

        foreach ($levels as [$name, $slug, $multiplier, $order]) {
            DB::table('cleaning_dirtiness_levels')->updateOrInsert(
                ['slug' => $slug],
                ['name' => $name, 'price_multiplier' => $multiplier, 'sort_order' => $order, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            );
        }        $categoryIds = DB::table('cleaning_special_service_categories')->pluck('id', 'slug');
        $services = [
            ['تنظيف الفرن من الداخل', 'oven-interior', 'kitchen-appliances', 'piece', 85, 75],
            ['تنظيف الثلاجة من الداخل', 'fridge-interior', 'kitchen-appliances', 'piece', 70, 60],
            ['غسيل الكنبايات بالبخار', 'sofa-steam', 'upholstery-carpets', 'seat', 35, 30],
            ['غسيل السجاد', 'carpet-wash', 'upholstery-carpets', 'sqm', 22, 20],
            ['تنظيف النوافذ', 'window-cleaning', 'glass-facades', 'piece', 18, 15],
            ['تنظيف ما بعد الدهان والصيانة', 'post-renovation', 'post-maintenance', 'sqm', 30, 180],
        ];

        foreach ($services as [$name, $slug, $category, $unit, $price, $duration]) {
            DB::table('cleaning_special_services')->updateOrInsert(
                ['slug' => $slug],
                [
                    'cleaning_special_service_category_id' => $categoryIds[$category],
                    'name' => $name,
                    'description' => 'خدمة احترافية تنفذ وفق مستوى الاتساخ ومتطلبات الموقع.',
                    'pricing_unit' => $unit,
                    'input_type' => 'quantity',
                    'unit_code' => $unit,
                    'base_unit_price' => $price,
                    'supports_dirtiness' => true,
                    'estimated_duration_minutes' => $duration,
                    'worker_pay_mode' => 'percentage',
                    'worker_pay_value' => 65,
                    'operating_cost_mode' => 'percentage',
                    'operating_cost_value' => 20,
                    'travel_fee_mode' => 'flat',
                    'travel_fee_value' => 10,
                    'requires_before_image' => in_array($slug, ['post-renovation', 'sofa-steam'], true),
                    'requires_after_image' => true,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }        if (Schema::hasTable('cleaning_special_service_equipment')) {
            $equipment = [
                ['ماكينة بخار احترافية', 'EQ-STEAM-001'],
                ['مكنسة رطب وجاف', 'EQ-WDV-001'],
                ['ماكينة غسيل سجاد ومفروشات', 'EQ-CARPET-001'],
                ['سلم ألمنيوم 4 درجات', 'EQ-LADDER-001'],
            ];

            foreach ($equipment as [$name, $code]) {
                DB::table('cleaning_special_service_equipment')->updateOrInsert(
                    ['asset_code' => $code],
                    [
                        'name' => $name,
                        'status' => 'available',
                        'buffer_before_minutes' => 15,
                        'buffer_after_minutes' => 20,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }

        $serviceIds = DB::table('cleaning_special_services')->pluck('id', 'slug');
        $levelIds = DB::table('cleaning_dirtiness_levels')->pluck('id', 'slug');

        if (Schema::hasTable('cleaning_special_service_dirtiness_levels')) {
            foreach ($serviceIds as $serviceId) {
                foreach ($levelIds as $levelId) {
                    DB::table('cleaning_special_service_dirtiness_levels')->updateOrInsert(
                        ['cleaning_special_service_id' => $serviceId, 'cleaning_dirtiness_level_id' => $levelId],
                        ['created_at' => now(), 'updated_at' => now()],
                    );
                }
            }
        }
    }    private function seedEventCatalog(): void
    {
        if (! Schema::hasTable('cleaning_event_types')) {
            return;
        }

        $events = [
            ['عشاء عائلي', 'family_dinner', 10],
            ['حفلة عيد ميلاد', 'birthday', 20],
            ['تجمع كبير', 'large_gathering', 30],
            ['عزاء', 'funeral', 40],
            ['مناسبة أخرى', 'other', 50],
        ];

        foreach ($events as [$name, $slug, $order]) {
            DB::table('cleaning_event_types')->updateOrInsert(
                ['slug' => $slug],
                ['name' => $name, 'sort_order' => $order, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        if (! Schema::hasTable('cleaning_event_type_fields')) {
            return;
        }

        $eventIds = DB::table('cleaning_event_types')->pluck('id', 'slug');
        foreach ($eventIds as $slug => $eventId) {
            $fields = [
                ['guest_count', 'عدد الضيوف', 'number', true, 10],
                ['venue_type', 'نوع المكان', 'select', true, 20],
                ['special_notes', 'ملاحظات خاصة', 'textarea', false, 30],
            ];

            foreach ($fields as [$key, $label, $type, $required, $order]) {
                DB::table('cleaning_event_type_fields')->updateOrInsert(
                    ['cleaning_event_type_id' => $eventId, 'key' => $key],
                    [
                        'label' => $label,
                        'field_type' => $type,
                        'is_required' => $required,
                        'options' => $key === 'venue_type' ? json_encode(['home', 'hall', 'garden', 'office'], JSON_THROW_ON_ERROR) : null,
                        'sort_order' => $order,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }
    }
}
