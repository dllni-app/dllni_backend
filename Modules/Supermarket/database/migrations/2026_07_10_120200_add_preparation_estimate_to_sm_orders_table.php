<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sm_orders', function (Blueprint $table): void {
            $table->timestamp('accepted_at')->nullable()->after('pickup_scheduled_for');
            $table->unsignedInteger('estimated_preparation_minutes')->nullable()->after('accepted_at');
            $table->timestamp('estimated_ready_at')->nullable()->after('estimated_preparation_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('sm_orders', function (Blueprint $table): void {
            $table->dropColumn(['accepted_at', 'estimated_preparation_minutes', 'estimated_ready_at']);
        });
    }
};
