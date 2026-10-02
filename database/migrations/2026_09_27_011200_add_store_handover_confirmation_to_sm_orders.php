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
            $table->timestamp('store_handover_confirmed_at')->nullable()->after('picked_up_at');
            $table->foreignId('store_handover_confirmed_by_user_id')
                ->nullable()
                ->after('store_handover_confirmed_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sm_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('store_handover_confirmed_by_user_id');
            $table->dropColumn('store_handover_confirmed_at');
        });
    }
};
