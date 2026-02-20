<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->string('slug')->nullable()->unique()->after('name');
            $table->boolean('is_system')->default(false)->after('guard_name');
            $table->index('is_system');
        });

        Schema::table('permissions', function (Blueprint $table): void {
            $table->string('slug')->nullable()->unique()->after('name');
            $table->string('group')->nullable()->after('guard_name');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropIndex(['is_system']);
            $table->dropColumn(['slug', 'is_system']);
        });

        Schema::table('permissions', function (Blueprint $table): void {
            $table->dropColumn(['slug', 'group']);
        });
    }
};
