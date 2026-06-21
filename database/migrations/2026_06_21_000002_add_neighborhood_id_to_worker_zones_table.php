<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_zones', function (Blueprint $table): void {
            $table->foreignId('neighborhood_id')
                ->nullable()
                ->after('worker_id')
                ->constrained('cleaning_neighborhoods')
                ->nullOnDelete();

            $table->unique(['worker_id', 'neighborhood_id']);
            $table->index(['neighborhood_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('worker_zones', function (Blueprint $table): void {
            $table->dropUnique('worker_zones_worker_id_neighborhood_id_unique');
            $table->dropIndex('worker_zones_neighborhood_id_is_active_index');
            $table->dropConstrainedForeignId('neighborhood_id');
        });
    }
};
