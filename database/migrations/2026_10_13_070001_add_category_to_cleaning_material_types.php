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
        if (! Schema::hasTable('cleaning_material_types') ||
            Schema::hasColumn('cleaning_material_types', 'category_key')) {
            return;
        }

        Schema::table('cleaning_material_types', function (Blueprint $table): void {
            $table->string('category_key', 40)->default('other');
        });

        // Backfill the existing Arabic seed catalog. New records can set
        // category_key directly; no guesses are made in the Flutter UI.
        $categories = [
            'cleaners' => ['منظف أرضيات', 'منظف زجاج', 'مزيل دهون'],
            'disinfectants' => ['مطهر أسطح', 'مبيض كلور'],
            'supplies' => ['أكياس نفايات', 'قفاز مايكروفايبر', 'إسفنجة تنظيف', 'قفازات تنظيف'],
        ];
        foreach ($categories as $category => $names) {
            DB::table('cleaning_material_types')
                ->whereIn('name', $names)
                ->update(['category_key' => $category]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cleaning_material_types') &&
            Schema::hasColumn('cleaning_material_types', 'category_key')) {
            Schema::table('cleaning_material_types', function (Blueprint $table): void {
                $table->dropColumn('category_key');
            });
        }
    }
};
