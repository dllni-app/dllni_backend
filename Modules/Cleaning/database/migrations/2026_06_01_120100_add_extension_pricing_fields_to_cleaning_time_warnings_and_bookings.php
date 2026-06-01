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
            if (! Schema::hasColumn('cleaning_time_warnings', 'quoted_amount')) {
                $table->decimal('quoted_amount', 10, 2)->default(0)->after('additional_minutes');
            }

            if (! Schema::hasColumn('cleaning_time_warnings', 'quoted_currency')) {
                $table->string('quoted_currency', 10)->default('SYP')->after('quoted_amount');
            }

            if (! Schema::hasColumn('cleaning_time_warnings', 'price_applied_at')) {
                $table->timestamp('price_applied_at')->nullable()->after('quoted_currency');
            }
        });

        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            if (! Schema::hasColumn('cleaning_bookings', 'extension_fee_total')) {
                $table->decimal('extension_fee_total', 10, 2)->default(0)->after('addons_total');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_time_warnings', function (Blueprint $table): void {
            $columns = [];

            if (Schema::hasColumn('cleaning_time_warnings', 'price_applied_at')) {
                $columns[] = 'price_applied_at';
            }

            if (Schema::hasColumn('cleaning_time_warnings', 'quoted_currency')) {
                $columns[] = 'quoted_currency';
            }

            if (Schema::hasColumn('cleaning_time_warnings', 'quoted_amount')) {
                $columns[] = 'quoted_amount';
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            if (Schema::hasColumn('cleaning_bookings', 'extension_fee_total')) {
                $table->dropColumn('extension_fee_total');
            }
        });
    }
};
