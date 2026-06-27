<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaning_member_bonuses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('users', indexName: 'cmb_customer_fk')->cascadeOnDelete();
            $table->foreignId('cleaning_automation_rule_id')->constrained('cleaning_automation_rules', indexName: 'cmb_rule_fk')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->string('trigger_type')->default('total_hours');
            $table->string('reward_type')->default('free_hours');
            $table->decimal('reward_value', 10, 2)->default(0);
            $table->decimal('earned_hours', 8, 2)->default(0);
            $table->decimal('required_hours', 8, 2)->default(0);
            $table->unsignedSmallInteger('period_months')->nullable();
            $table->timestamp('qualifying_started_at')->nullable();
            $table->timestamp('qualifying_ended_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users', indexName: 'cmb_activated_by_fk')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'cleaning_automation_rule_id', 'status'], 'cmb_customer_rule_status_idx');
            $table->index(['status', 'created_at'], 'cmb_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_member_bonuses');
    }
};
