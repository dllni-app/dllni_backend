<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table): void {
            $table->string('delivery_failure_code', 64)->nullable()->after('cancel_reason');
            $table->text('delivery_failure_reason')->nullable()->after('delivery_failure_code');
            $table->timestamp('delivery_failed_at')->nullable()->after('delivery_failure_reason');
            $table->timestamp('returned_to_merchant_at')->nullable()->after('delivery_failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'delivery_failure_code',
                'delivery_failure_reason',
                'delivery_failed_at',
                'returned_to_merchant_at',
            ]);
        });
    }
};
