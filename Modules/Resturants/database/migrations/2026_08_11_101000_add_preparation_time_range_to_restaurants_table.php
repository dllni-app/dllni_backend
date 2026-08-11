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
        Schema::table('restaurants', function (Blueprint $table): void {
            $table->unsignedSmallInteger('estimated_preparation_time_min')
                ->nullable()
                ->after('estimated_preparation_time');
            $table->unsignedSmallInteger('estimated_preparation_time_max')
                ->nullable()
                ->after('estimated_preparation_time_min');
        });

        DB::table('restaurants')
            ->whereNotNull('estimated_preparation_time')
            ->update([
                'estimated_preparation_time_min' => DB::raw(
                    'CASE WHEN estimated_preparation_time - 10 < 1 THEN 1 ELSE estimated_preparation_time - 10 END'
                ),
                'estimated_preparation_time_max' => DB::raw('estimated_preparation_time'),
            ]);
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table): void {
            $table->dropColumn([
                'estimated_preparation_time_min',
                'estimated_preparation_time_max',
            ]);
        });
    }
};
