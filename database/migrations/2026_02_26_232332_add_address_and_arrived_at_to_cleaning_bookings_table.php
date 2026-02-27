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
            $table->decimal('address_latitude', 10, 8)->nullable()->after('property_details');
            $table->decimal('address_longitude', 11, 8)->nullable()->after('address_latitude');
            $table->timestamp('arrived_at')->nullable()->after('started_travel_at');
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->dropColumn(['address_latitude', 'address_longitude', 'arrived_at']);
        });
    }
};
