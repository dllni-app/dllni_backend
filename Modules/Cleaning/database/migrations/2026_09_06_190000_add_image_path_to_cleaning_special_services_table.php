<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cleaning_special_services')
            || Schema::hasColumn('cleaning_special_services', 'image_path')) {
            return;
        }

        Schema::table('cleaning_special_services', function (Blueprint $table): void {
            $table->string('image_path')->nullable()->after('image_url');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cleaning_special_services')
            || ! Schema::hasColumn('cleaning_special_services', 'image_path')) {
            return;
        }

        Schema::table('cleaning_special_services', function (Blueprint $table): void {
            $table->dropColumn('image_path');
        });
    }
};
