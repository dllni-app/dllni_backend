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
        Schema::create('cleaning_v3_operational_approvals', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_id')->constrained('cleaning_bookings', 'id', 'cv3oa_booking_fk')->restrictOnDelete();
            $table->uuid('quote_id');
            $table->foreign('quote_id', 'cv3oa_quote_fk')->references('id')->on('cleaning_booking_quotes')->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users', 'id', 'cv3oa_actor_fk')->restrictOnDelete();
            $table->unsignedInteger('price_revision');
            $table->json('visit_ids');
            $table->timestamp('approved_at');
            $table->unique('booking_id', 'cv3oa_booking_uq');
        });
    }

    public function down(): void
    {
        if (DB::table('cleaning_v3_operational_approvals')->exists()) {
            throw new RuntimeException('Cannot delete V3 operational approval history.');
        }
        Schema::dropIfExists('cleaning_v3_operational_approvals');
    }
};
