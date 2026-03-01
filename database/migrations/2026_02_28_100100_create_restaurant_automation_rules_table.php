<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_automation_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type');
            $table->boolean('is_active')->default(true);
            $table->json('conditions')->nullable();
            $table->json('actions')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_automation_rules');
    }
};
