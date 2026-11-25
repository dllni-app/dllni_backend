<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_booking_quotes', function (Blueprint $table): void {
            $table->json('v3_confirmed_draft')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('cleaning_booking_quotes')->whereNotNull('v3_confirmed_draft')->exists()) {
            throw new RuntimeException('Preserve accepted V3 draft history.');
        }
        Schema::table('cleaning_booking_quotes', fn (Blueprint $table) => $table->dropColumn('v3_confirmed_draft'));
    }
};
