<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('document_type');
            $table->string('verification_status')->default('pending');
            $table->string('file_path')->nullable();
            $table->timestamps();

            $table->index(['restaurant_id', 'document_type', 'verification_status'], 'rd_rest_doc_ver_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_documents');
    }
};
