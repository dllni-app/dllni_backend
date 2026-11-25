<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaning_v3_supervisor_cash_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_id')->unique()->constrained('cleaning_booking_sessions')->restrictOnDelete();
            $table->foreignId('collector_user_id')->constrained('users')->restrictOnDelete();
            $table->char('calculation_digest', 64);
            $table->decimal('customer_amount', 14, 2);
            $table->decimal('worker_amount', 14, 2);
            $table->decimal('supervisor_amount', 14, 2);
            $table->decimal('company_amount', 14, 2);
            $table->string('report_reference', 128)->unique();
            $table->string('report_evidence', 500);
            $table->timestamp('reported_at');
            $table->string('receipt_reference', 128)->nullable()->unique();
            $table->string('receipt_evidence', 500)->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });
        Schema::create('cleaning_v3_supervisor_cash_earnings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('cleaning_v3_supervisor_cash_accounts')->restrictOnDelete();
            $table->foreignId('supervisor_user_id')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->unique(['account_id', 'supervisor_user_id'], 'cv3sce_account_supervisor_uq');
        });
    }

    public function down(): void
    {
        if (DB::table('cleaning_v3_supervisor_cash_accounts')->exists()) {
            throw new RuntimeException('Cannot delete supervisor cash accounting history.');
        }
        Schema::dropIfExists('cleaning_v3_supervisor_cash_earnings');
        Schema::dropIfExists('cleaning_v3_supervisor_cash_accounts');
    }
};
