<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_customer_ratings', function (Blueprint $table): void {
            $table->dropUnique('wcr_booking_rating_unique');
            $table->unique(
                ['booking_id', 'booking_type', 'worker_id', 'customer_id', 'rating_type'],
                'wcr_booking_worker_customer_rating_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('worker_customer_ratings', function (Blueprint $table): void {
            $table->dropUnique('wcr_booking_worker_customer_rating_unique');
            $table->unique(['booking_id', 'booking_type', 'rating_type'], 'wcr_booking_rating_unique');
        });
    }
};
