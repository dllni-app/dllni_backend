<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table): void {
            $table->boolean('show_upcoming')->default(false);
            $table->index(['is_active', 'show_upcoming', 'starts_at'], 'offers_upcoming_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table): void {
            $table->dropIndex('offers_upcoming_lookup');
            $table->dropColumn('show_upcoming');
        });
    }
};
