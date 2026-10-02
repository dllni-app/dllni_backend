<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_documents', function (Blueprint $table): void {
            $table->timestamp('verified_at')->nullable()->after('verification_status');
            $table->foreignId('verified_by_user_id')->nullable()->after('verified_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable()->after('verified_by_user_id');
            $table->text('rejection_reason')->nullable()->after('expires_at');
            $table->index(['verification_status', 'expires_at'], 'restaurant_docs_status_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_documents', function (Blueprint $table): void {
            $table->dropIndex('restaurant_docs_status_expiry_idx');
            $table->dropConstrainedForeignId('verified_by_user_id');
            $table->dropColumn(['verified_at', 'expires_at', 'rejection_reason']);
        });
    }
};
