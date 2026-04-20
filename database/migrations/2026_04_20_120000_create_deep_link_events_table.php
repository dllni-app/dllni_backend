<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deep_link_events', function (Blueprint $table): void {
            $table->id();
            $table->string('action', 32); // resolve, click, open, short_redirect
            $table->string('status', 32)->nullable();
            $table->string('resource_type', 64)->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('resource_slug', 255)->nullable();

            $table->string('source', 100)->nullable();
            $table->string('medium', 100)->nullable();
            $table->string('campaign', 100)->nullable();
            $table->unsignedBigInteger('sharer_id')->nullable();

            $table->string('platform', 50)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('ip', 64)->nullable();
            $table->string('referer', 512)->nullable();

            $table->string('full_url', 2048)->nullable();
            $table->string('path', 1024)->nullable();
            $table->json('query_params')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['action', 'created_at']);
            $table->index(['resource_type', 'resource_id']);
            $table->index(['source', 'medium', 'campaign']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deep_link_events');
    }
};
