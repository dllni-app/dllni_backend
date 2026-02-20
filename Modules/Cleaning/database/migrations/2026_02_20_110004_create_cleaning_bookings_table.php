<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaning_bookings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('worker_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('preferred_worker_id')->nullable()->constrained('workers')->nullOnDelete();
            $table->foreignId('cancellation_policy_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('billing_policy_id')->nullable()->constrained('cleaning_billing_policies')->nullOnDelete();
            $table->string('booking_number')->unique();
            $table->string('status');
            $table->string('property_type');
            $table->json('property_details')->nullable();
            $table->decimal('estimated_sqm', 10, 2)->nullable();
            $table->decimal('estimated_hours', 8, 2)->nullable();
            $table->date('scheduled_date');
            $table->time('scheduled_time');
            $table->decimal('total_hours', 8, 2)->default(0);
            $table->decimal('base_price', 10, 2)->default(0);
            $table->decimal('addons_total', 10, 2)->default(0);
            $table->decimal('travel_fee', 10, 2)->default(0);
            $table->decimal('cancellation_fee', 10, 2)->default(0);
            $table->decimal('total_price', 10, 2)->default(0);
            $table->boolean('terms_accepted')->default(false);
            $table->timestamp('work_started_at')->nullable();
            $table->timestamp('work_finished_at')->nullable();
            $table->timestamp('customer_confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['worker_id', 'status']);
            $table->index('scheduled_date');
            $table->index('billing_policy_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_bookings');
    }
};
