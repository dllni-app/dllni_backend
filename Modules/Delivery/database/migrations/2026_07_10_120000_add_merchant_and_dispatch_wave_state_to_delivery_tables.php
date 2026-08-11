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
            $table->string('merchant_status')->nullable()->after('source_id');
            $table->timestamp('merchant_accepted_at')->nullable()->after('merchant_status');
            $table->unsignedInteger('estimated_preparation_minutes')->nullable()->after('merchant_accepted_at');
            $table->timestamp('estimated_ready_at')->nullable()->after('estimated_preparation_minutes');
            $table->timestamp('merchant_ready_at')->nullable()->after('estimated_ready_at');
            $table->unsignedInteger('dispatch_wave')->default(0)->after('merchant_ready_at');
            $table->decimal('search_radius_km', 10, 3)->nullable()->after('dispatch_wave');
            $table->string('dispatch_phase')->default('radius')->after('search_radius_km');
        });

        Schema::table('delivery_assignment_attempts', function (Blueprint $table): void {
            $table->unsignedInteger('dispatch_wave')->default(1)->after('attempt_no');
            $table->string('candidate_tier')->default('located')->after('dispatch_wave');
            $table->index(['order_id', 'dispatch_wave', 'status'], 'daa_order_wave_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_assignment_attempts', function (Blueprint $table): void {
            $table->dropIndex('daa_order_wave_status_idx');
            $table->dropColumn(['dispatch_wave', 'candidate_tier']);
        });

        Schema::table('delivery_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'merchant_status',
                'merchant_accepted_at',
                'estimated_preparation_minutes',
                'estimated_ready_at',
                'merchant_ready_at',
                'dispatch_wave',
                'search_radius_km',
                'dispatch_phase',
            ]);
        });
    }
};
