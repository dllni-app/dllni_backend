<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_financial_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('cleaning_financial_settings', 'user_cancellation_fee')) {
                $table->decimal('user_cancellation_fee', 12, 2)->default(0)->after('cleaning_deep_multiplier');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_financial_settings', function (Blueprint $table): void {
            if (Schema::hasColumn('cleaning_financial_settings', 'user_cancellation_fee')) {
                $table->dropColumn('user_cancellation_fee');
            }
        });
    }
};
