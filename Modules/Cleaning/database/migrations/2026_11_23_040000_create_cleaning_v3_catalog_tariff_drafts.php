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
        if (Schema::hasTable('cleaning_v3_catalog_tariff_rules')) {
            throw new RuntimeException('Existing V3 tariff rule table requires explicit reconciliation.');
        }

        Schema::create('cleaning_v3_catalog_tariff_rules', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catalog_item_id')
                ->constrained('cleaning_v3_catalog_items', 'id', 'cv3_tariff_item_fk')
                ->restrictOnDelete();
            $table->string('rule_key', 100);
            $table->string('cleaning_mode', 16);
            // whole for full cleaning; individual component codes for custom
            // deep cleaning (floors, walls, equipment pieces, etc.).
            $table->string('scope_key', 100)->default('whole');
            $table->decimal('quantity_min', 12, 2);
            $table->decimal('quantity_max', 12, 2);
            $table->decimal('unit_price', 16, 2);
            $table->decimal('unit_effort_minutes', 10, 2);
            $table->string('currency', 3)->default('SYP');
            $table->boolean('is_enabled')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['catalog_item_id', 'rule_key'], 'cv3_tariff_rule_key_uq');
            $table->index(
                ['catalog_item_id', 'cleaning_mode', 'scope_key', 'is_enabled'],
                'cv3_tariff_coverage_idx',
            );
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('cleaning_v3_catalog_tariff_rules')
            && DB::table('cleaning_v3_catalog_tariff_rules')->exists()) {
            throw new RuntimeException('Refusing to delete catalog tariff draft audit records.');
        }

        Schema::dropIfExists('cleaning_v3_catalog_tariff_rules');
    }
};
