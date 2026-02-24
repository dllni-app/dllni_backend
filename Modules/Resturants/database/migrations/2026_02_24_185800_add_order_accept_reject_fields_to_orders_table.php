<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedInteger('estimated_preparation_minutes')->nullable()->after('accepted_at');
            $table->text('kitchen_notes')->nullable()->after('estimated_preparation_minutes');
            $table->string('cancellation_reason_code')->nullable()->after('cancellation_reason');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['estimated_preparation_minutes', 'kitchen_notes', 'cancellation_reason_code']);
        });
    }
};
