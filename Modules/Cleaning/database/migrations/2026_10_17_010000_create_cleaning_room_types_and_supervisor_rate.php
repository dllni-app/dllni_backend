<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Cleaning\Support\CleaningFinancialDefaults;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cleaning_room_types')) {
            Schema::create('cleaning_room_types', static function (Blueprint $table): void {
                $table->id();
                $table->string('code', 64)->unique();
                $table->string('name', 150);
                $table->string('icon_key', 80)->nullable();
                $table->decimal('regular_hourly_rate', 12, 2)->default(0);
                $table->decimal('deep_hourly_rate', 12, 2)->default(0);
                $table->json('suggested_minutes_by_size')->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('cleaning_financial_settings')
            && ! Schema::hasColumn('cleaning_financial_settings', 'supervisor_hourly_rate')) {
            Schema::table('cleaning_financial_settings', static function (Blueprint $table): void {
                $table->decimal('supervisor_hourly_rate', 12, 2)->default(0);
            });
        }

        $settings = Schema::hasTable('cleaning_financial_settings')
            ? DB::table('cleaning_financial_settings')->first() : null;
        $base = max(0, (float) ($settings->cleaning_base_unit_price ?? CleaningFinancialDefaults::BASE_UNIT_PRICE));
        $units = json_decode((string) ($settings->cleaning_room_pricing_units ?? ''), true);
        $deep = json_decode((string) ($settings->cleaning_room_deep_multipliers ?? ''), true);
        $minutes = json_decode((string) ($settings->cleaning_room_time_minutes ?? ''), true);
        $units = is_array($units) ? $units : CleaningFinancialDefaults::roomPricingUnits();
        $deep = is_array($deep) ? $deep : CleaningFinancialDefaults::roomDeepMultipliers();
        $minutes = is_array($minutes) ? $minutes : CleaningFinancialDefaults::roomTimeMinutes();
        $defaultUnits = CleaningFinancialDefaults::roomPricingUnits();
        $defaultMinutes = CleaningFinancialDefaults::roomTimeMinutes();
        $labels = ['bedroom' => 'غرفة نوم', 'bathroom' => 'حمام', 'toilet' => 'مرحاض', 'kitchen' => 'مطبخ', 'living_room' => 'غرفة معيشة', 'balcony' => 'شرفة', 'corridor' => 'ممر', 'shed' => 'مستودع'];
        foreach (CleaningFinancialDefaults::ROOM_TYPES as $order => $code) {
            $regularMinutes = max(1, (int) ($minutes[$code]['medium']['regular'] ?? $defaultMinutes[$code]['medium']['regular']));
            $deepMinutes = max(1, (int) ($minutes[$code]['medium']['deep'] ?? $defaultMinutes[$code]['medium']['deep']));
            $unit = max(0, (float) ($units[$code]['medium'] ?? $defaultUnits[$code]['medium']));
            $multiplier = max(1, (float) ($deep[$code]['medium'] ?? $settings->cleaning_deep_multiplier ?? CleaningFinancialDefaults::DEEP_CLEANING_MULTIPLIER));
            DB::table('cleaning_room_types')->updateOrInsert(['code' => $code], [
                'name' => $labels[$code],
                'icon_key' => $code,
                'regular_hourly_rate' => round($base * $unit * 60 / $regularMinutes, 2),
                'deep_hourly_rate' => round($base * $unit * $multiplier * 60 / $deepMinutes, 2),
                'suggested_minutes_by_size' => json_encode([
                    'small' => [
                        'regular' => (int) ($minutes[$code]['small']['regular'] ?? $defaultMinutes[$code]['small']['regular']),
                        'deep' => (int) ($minutes[$code]['small']['deep'] ?? $defaultMinutes[$code]['small']['deep']),
                    ],
                    'medium' => ['regular' => $regularMinutes, 'deep' => $deepMinutes],
                    'large' => [
                        'regular' => (int) ($minutes[$code]['large']['regular'] ?? $defaultMinutes[$code]['large']['regular']),
                        'deep' => (int) ($minutes[$code]['large']['deep'] ?? $defaultMinutes[$code]['large']['deep']),
                    ],
                ], JSON_THROW_ON_ERROR),
                'sort_order' => $order + 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_room_types');
        if (Schema::hasTable('cleaning_financial_settings') && Schema::hasColumn('cleaning_financial_settings', 'supervisor_hourly_rate')) {
            Schema::table('cleaning_financial_settings', static function (Blueprint $table): void {
                $table->dropColumn('supervisor_hourly_rate');
            });
        }
    }
};
