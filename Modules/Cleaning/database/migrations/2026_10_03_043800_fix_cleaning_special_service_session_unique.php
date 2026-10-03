<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_booking_special_services', function (Blueprint $table): void {
            $table->index('cleaning_booking_id', 'clean_book_special_booking_idx');
        });

        Schema::table('cleaning_booking_special_services', function (Blueprint $table): void {
            $table->dropUnique('clean_book_special_unique');
            $table->unique(
                ['cleaning_booking_id', 'cleaning_special_service_id', 'cleaning_booking_session_id'],
                'clean_book_special_session_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_booking_special_services', function (Blueprint $table): void {
            $table->dropUnique('clean_book_special_session_unique');
            $table->unique(
                ['cleaning_booking_id', 'cleaning_special_service_id'],
                'clean_book_special_unique'
            );
        });
    }
};
