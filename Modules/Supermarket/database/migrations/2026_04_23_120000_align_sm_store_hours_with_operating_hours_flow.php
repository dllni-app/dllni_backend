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
        Schema::table('sm_store_hours', function (Blueprint $table): void {
            $table->string('day_of_week')->change();
            $table->renameColumn('opens_at', 'open_time');
            $table->renameColumn('closes_at', 'close_time');
        });

        DB::statement("
            UPDATE sm_store_hours
            SET day_of_week = CASE day_of_week
                WHEN '0' THEN 'monday'
                WHEN '1' THEN 'tuesday'
                WHEN '2' THEN 'wednesday'
                WHEN '3' THEN 'thursday'
                WHEN '4' THEN 'friday'
                WHEN '5' THEN 'saturday'
                WHEN '6' THEN 'sunday'
                ELSE LOWER(day_of_week)
            END
        ");

        if (! Schema::hasColumn('sm_stores', 'is_temporarily_closed')) {
            Schema::table('sm_stores', function (Blueprint $table): void {
                $table->boolean('is_temporarily_closed')->default(false)->after('is_featured');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sm_stores', 'is_temporarily_closed')) {
            Schema::table('sm_stores', function (Blueprint $table): void {
                $table->dropColumn('is_temporarily_closed');
            });
        }

        DB::statement("
            UPDATE sm_store_hours
            SET day_of_week = CASE LOWER(day_of_week)
                WHEN 'monday' THEN '0'
                WHEN 'tuesday' THEN '1'
                WHEN 'wednesday' THEN '2'
                WHEN 'thursday' THEN '3'
                WHEN 'friday' THEN '4'
                WHEN 'saturday' THEN '5'
                WHEN 'sunday' THEN '6'
                ELSE day_of_week
            END
        ");

        Schema::table('sm_store_hours', function (Blueprint $table): void {
            $table->unsignedTinyInteger('day_of_week')->change();
            $table->renameColumn('open_time', 'opens_at');
            $table->renameColumn('close_time', 'closes_at');
        });
    }
};
