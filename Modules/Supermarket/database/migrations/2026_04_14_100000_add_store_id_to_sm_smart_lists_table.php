<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sm_smart_lists', function (Blueprint $table): void {
            $table->foreignId('store_id')->nullable()->after('user_id')->constrained('sm_stores')->nullOnDelete();
            $table->index(['store_id', 'is_active'], 'sm_slist_store_active_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sm_smart_lists', function (Blueprint $table): void {
            $table->dropIndex('sm_slist_store_active_idx');
            $table->dropConstrainedForeignId('store_id');
        });
    }
};
