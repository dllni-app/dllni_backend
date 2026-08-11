<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_time_warnings', function (Blueprint $table): void {
            $table->decimal('quoted_base_amount', 10, 2)->nullable()->after('additional_minutes');
            $table->decimal('quoted_admin_margin_amount', 10, 2)->nullable()->after('quoted_base_amount');
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_time_warnings', function (Blueprint $table): void {
            $table->dropColumn([
                'quoted_base_amount',
                'quoted_admin_margin_amount',
            ]);
        });
    }
};
