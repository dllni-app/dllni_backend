<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_financial_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id');
            $table->string('currency', 3)->default('SYP');
            $table->decimal('current_balance', 14, 2)->default(0);
            $table->decimal('financial_limit', 14, 2)->default(0);
            $table->boolean('is_suspended')->default(false);
            $table->string('suspension_reason')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'currency']);
            $table->index(['owner_type', 'owner_id']);
            $table->index('is_suspended');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_financial_accounts');
    }
};
