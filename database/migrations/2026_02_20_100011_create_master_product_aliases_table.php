<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_product_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('master_product_id')->constrained()->cascadeOnDelete();
            $table->string('alias');
            $table->timestamps();

            $table->index(['master_product_id', 'alias']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_product_aliases');
    }
};
