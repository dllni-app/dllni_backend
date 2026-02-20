<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sm_store_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('sm_stores')->cascadeOnDelete();
            $table->string('document_type');
            $table->string('file_path');
            $table->string('verification_status')->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'document_type', 'verification_status'], 'sm_store_doc_store_type_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sm_store_documents');
    }
};
