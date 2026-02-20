<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaning_billing_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('billing_mode');
            $table->json('rules')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['is_active', 'is_default', 'billing_mode'], 'cbp_active_default_mode_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_billing_policies');
    }
};
