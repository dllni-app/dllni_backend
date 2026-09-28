<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_alerts', function (Blueprint $table): void {
            $table->timestamp('acknowledged_at')->nullable()->after('status');
            $table->foreignId('acknowledged_by')->nullable()->after('acknowledged_at')->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable()->after('acknowledged_by');
            $table->foreignId('resolved_by')->nullable()->after('resolved_at')->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable()->after('resolved_by');
        });
    }

    public function down(): void
    {
        Schema::table('system_alerts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('acknowledged_by');
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn(['acknowledged_at', 'resolved_at', 'resolution_note']);
        });
    }
};
