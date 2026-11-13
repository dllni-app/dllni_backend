<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workers') || Schema::hasColumn('workers', 'preferred_work_types')) {
            return;
        }

        Schema::table('workers', function (Blueprint $table): void {
            $table->json('preferred_work_types')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('workers') && Schema::hasColumn('workers', 'preferred_work_types')) {
            Schema::table('workers', function (Blueprint $table): void {
                $table->dropColumn('preferred_work_types');
            });
        }
    }
};
