<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_order_disputes', function (Blueprint $table): void {
            $table->string('resolution_type')->nullable()->after('status');
            $table->decimal('refund_amount', 10, 2)->nullable()->after('resolution_type');
            $table->decimal('deduction_amount', 10, 2)->nullable()->after('refund_amount');
            $table->string('payout_hold_status')->default('held')->after('deduction_amount');
            $table->foreignId('resolved_by_user_id')->nullable()->after('description')->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable()->after('resolved_by_user_id');
            $table->text('admin_note')->nullable()->after('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_order_disputes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('resolved_by_user_id');
            $table->dropColumn([
                'resolution_type',
                'refund_amount',
                'deduction_amount',
                'payout_hold_status',
                'resolved_at',
                'admin_note',
            ]);
        });
    }
};
