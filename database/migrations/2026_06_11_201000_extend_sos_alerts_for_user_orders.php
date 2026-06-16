<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sos_alerts', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->after('user_id')->constrained('orders')->cascadeOnDelete();
            $table->text('message')->nullable()->after('emergency_type');
            $table->string('source')->default('booking')->after('message');

            $table->index(['user_id', 'status']);
            $table->index(['order_id', 'status']);
            $table->index(['source', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('sos_alerts', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'status']);
            $table->dropIndex(['order_id', 'status']);
            $table->dropIndex(['source', 'status']);
            $table->dropConstrainedForeignId('user_id');
            $table->dropConstrainedForeignId('order_id');
            $table->dropColumn(['message', 'source']);
        });
    }
};
