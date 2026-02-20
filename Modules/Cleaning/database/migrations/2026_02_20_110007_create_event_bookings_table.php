<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_bookings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('cancellation_policy_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('billing_policy_id')->nullable()->constrained('cleaning_billing_policies')->nullOnDelete();
            $table->string('booking_number')->unique();
            $table->string('status');
            $table->string('event_type');
            $table->unsignedInteger('guest_count_min')->nullable();
            $table->unsignedInteger('guest_count_max')->nullable();
            $table->string('gender_preference')->nullable();
            $table->unsignedInteger('suggested_team_size')->nullable();
            $table->date('scheduled_date');
            $table->time('scheduled_time');
            $table->decimal('total_hours', 8, 2)->default(0);
            $table->decimal('base_price', 10, 2)->default(0);
            $table->decimal('travel_fee', 10, 2)->default(0);
            $table->decimal('total_price', 10, 2)->default(0);
            $table->boolean('terms_accepted')->default(false);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index('scheduled_date');
            $table->index('billing_policy_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_bookings');
    }
};
