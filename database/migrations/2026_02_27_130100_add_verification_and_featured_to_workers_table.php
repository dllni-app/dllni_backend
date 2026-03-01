<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table): void {
            $table->boolean('is_verified')->default(false)->after('is_suspended');
            $table->boolean('is_featured')->default(false)->after('is_verified');
            $table->timestamp('featured_until')->nullable()->after('is_featured');
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table): void {
            $table->dropColumn(['is_verified', 'is_featured', 'featured_until']);
        });
    }
};
