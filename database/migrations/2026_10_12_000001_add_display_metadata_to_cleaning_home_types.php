<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_home_types', function (Blueprint $table): void {
            $table->string('icon_key', 64)->nullable()->after('title');
            $table->text('description')->nullable()->after('icon_key');
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_home_types', function (Blueprint $table): void {
            $table->dropColumn(['icon_key', 'description']);
        });
    }
};
