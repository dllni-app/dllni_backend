<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deep_link_short_urls', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('target_url', 2048);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('max_clicks')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deep_link_short_urls');
    }
};
