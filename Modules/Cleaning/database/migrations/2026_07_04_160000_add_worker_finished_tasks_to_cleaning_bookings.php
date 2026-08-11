<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            if (! Schema::hasColumn('cleaning_bookings', 'worker_finished_cleaning_services')) {
                $table->json('worker_finished_cleaning_services')->nullable()->after('worker_completion_message');
            }

            if (! Schema::hasColumn('cleaning_bookings', 'worker_finished_property_rooms')) {
                $table->json('worker_finished_property_rooms')->nullable()->after('worker_finished_cleaning_services');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            if (Schema::hasColumn('cleaning_bookings', 'worker_finished_property_rooms')) {
                $table->dropColumn('worker_finished_property_rooms');
            }

            if (Schema::hasColumn('cleaning_bookings', 'worker_finished_cleaning_services')) {
                $table->dropColumn('worker_finished_cleaning_services');
            }
        });
    }
};
