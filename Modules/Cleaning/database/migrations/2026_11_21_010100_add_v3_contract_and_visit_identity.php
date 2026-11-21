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
        // V2 data is intentionally not backfilled or re-priced. NULL means the
        // pre-existing V2 contract until a future version-aware read API exists.
        Schema::table('cleaning_bookings', static function (Blueprint $table): void {
            $table->string('contract_version', 8)->nullable()->index('cv3_booking_contract_idx');
            $table->foreignId('catalog_version_id')->nullable()
                ->constrained('cleaning_v3_catalog_versions', 'id', 'cv3_booking_catalog_fk')->restrictOnDelete();
        });

        Schema::table('cleaning_booking_quotes', static function (Blueprint $table): void {
            $table->string('contract_version', 8)->nullable()->index('cv3_quote_contract_idx');
            $table->foreignId('catalog_version_id')->nullable()
                ->constrained('cleaning_v3_catalog_versions', 'id', 'cv3_quote_catalog_fk')->restrictOnDelete();
        });

        Schema::table('cleaning_booking_sessions', static function (Blueprint $table): void {
            // Appointment groups are non-chargeable. Each visit is still the
            // existing persisted cleaning_booking_sessions.id.
            $table->string('appointment_key', 96)->nullable()->index('cv3_visit_appointment_idx');
            $table->string('occurrence_key', 96)->nullable();
            $table->unsignedInteger('price_revision')->default(0);
            $table->unsignedInteger('clock_revision')->default(0);
            $table->unique(['cleaning_booking_id', 'occurrence_key'], 'cv3_visit_occurrence_uq');
        });
    }

    public function down(): void
    {
        // Forward migration is reversible only if no V3 data has been written.
        $hasV3 = DB::table('cleaning_bookings')->where('contract_version', 'v3')->exists()
            || DB::table('cleaning_booking_quotes')->where('contract_version', 'v3')->exists()
            || DB::table('cleaning_booking_sessions')->whereNotNull('occurrence_key')->exists();
        if ($hasV3) {
            throw new RuntimeException('Refusing to remove V3 visit identity from existing records.');
        }

        Schema::table('cleaning_booking_sessions', static function (Blueprint $table): void {
            $table->dropUnique('cv3_visit_occurrence_uq');
            $table->dropIndex('cv3_visit_appointment_idx');
            $table->dropColumn(['appointment_key', 'occurrence_key', 'price_revision', 'clock_revision']);
        });

        Schema::table('cleaning_booking_quotes', static function (Blueprint $table): void {
            $table->dropForeign('cv3_quote_catalog_fk');
            $table->dropIndex('cv3_quote_contract_idx');
            $table->dropColumn(['contract_version', 'catalog_version_id']);
        });

        Schema::table('cleaning_bookings', static function (Blueprint $table): void {
            $table->dropForeign('cv3_booking_catalog_fk');
            $table->dropIndex('cv3_booking_contract_idx');
            $table->dropColumn(['contract_version', 'catalog_version_id']);
        });
    }
};
