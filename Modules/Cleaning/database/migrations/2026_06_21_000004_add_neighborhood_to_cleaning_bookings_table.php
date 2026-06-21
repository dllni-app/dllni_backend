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
            $table->foreignId('neighborhood_id')
                ->nullable()
                ->after('address_longitude')
                ->constrained('cleaning_neighborhoods')
                ->nullOnDelete();

            $table->string('neighborhood_name')->nullable()->after('neighborhood_id');
            $table->index(['status', 'neighborhood_id']);
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->dropIndex('cleaning_bookings_status_neighborhood_id_index');
            $table->dropColumn('neighborhood_name');
            $table->dropConstrainedForeignId('neighborhood_id');
        });
    }
};
