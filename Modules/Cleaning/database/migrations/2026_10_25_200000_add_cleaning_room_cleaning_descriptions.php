<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cleaning_room_types')) {
            return;
        }

        Schema::table('cleaning_room_types', static function (Blueprint $table): void {
            if (! Schema::hasColumn('cleaning_room_types', 'regular_description')) {
                $table->text('regular_description')->nullable();
            }
            if (! Schema::hasColumn('cleaning_room_types', 'deep_description')) {
                $table->text('deep_description')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cleaning_room_types')) {
            return;
        }
        Schema::table('cleaning_room_types', static function (Blueprint $table): void {
            if (Schema::hasColumn('cleaning_room_types', 'regular_description')) {
                $table->dropColumn('regular_description');
            }
            if (Schema::hasColumn('cleaning_room_types', 'deep_description')) {
                $table->dropColumn('deep_description');
            }
        });
    }
};
