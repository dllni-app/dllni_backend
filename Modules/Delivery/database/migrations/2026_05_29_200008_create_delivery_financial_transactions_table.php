<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_financial_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('delivery_financial_accounts')->cascadeOnDelete();
            $table->string('transaction_type');
            $table->string('direction');
            $table->decimal('amount', 14, 2);
            $table->decimal('balance_before', 14, 2);
            $table->decimal('balance_after', 14, 2);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['account_id', 'created_at']);
            $table->index(['reference_type', 'reference_id'], 'dft_ref_type_ref_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_financial_transactions');
    }
};
