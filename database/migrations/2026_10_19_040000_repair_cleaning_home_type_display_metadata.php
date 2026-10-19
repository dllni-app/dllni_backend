<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // Repair databases where the older metadata migration was marked as
        // complete but either optional column was never created or was lost.
        if (! Schema::hasColumn('cleaning_home_types', 'icon_key')) {
            Schema::table('cleaning_home_types', function (Blueprint $table): void {
                $table->string('icon_key', 64)->nullable()->after('title');
            });
        }

        if (! Schema::hasColumn('cleaning_home_types', 'description')) {
            Schema::table('cleaning_home_types', function (Blueprint $table): void {
                $table->text('description')->nullable()->after('icon_key');
            });
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive: these existing columns contain
        // admin-managed data and may predate this repair migration.
    }
};
