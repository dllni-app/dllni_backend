<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaning_booking_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->json('inputs');
            $table->unsignedInteger('revision')->default(1);
            $table->uuid('creation_key');
            $table->string('creation_hash', 64);
            $table->timestamps();
            $table->unique(['customer_id', 'creation_key'], 'cleaning_templates_creation_unique');
            $table->index(['customer_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_booking_templates');
    }
};
