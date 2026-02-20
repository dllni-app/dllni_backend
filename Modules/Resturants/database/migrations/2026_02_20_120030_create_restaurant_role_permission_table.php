<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_role_permission', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['restaurant_role_id', 'permission_id'], 'rrp_role_permission_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_role_permission');
    }
};
