<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cleaning_automation_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type'); // suspend | reward
            $table->boolean('is_active')->default(true);
            $table->json('conditions')->nullable();
            $table->json('actions')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cleaning_automation_rules');
    }
};
