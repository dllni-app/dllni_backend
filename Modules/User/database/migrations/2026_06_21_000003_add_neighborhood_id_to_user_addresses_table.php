<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_addresses', function (Blueprint $table): void {
            $table->foreignId('neighborhood_id')
                ->nullable()
                ->after('neighborhood')
                ->constrained('cleaning_neighborhoods')
                ->nullOnDelete();

            $table->index('neighborhood_id');
        });
    }

    public function down(): void
    {
        Schema::table('user_addresses', function (Blueprint $table): void {
            $table->dropIndex('user_addresses_neighborhood_id_index');
            $table->dropConstrainedForeignId('neighborhood_id');
        });
    }
};
