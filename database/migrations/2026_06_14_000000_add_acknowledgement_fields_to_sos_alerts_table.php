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
            $table->timestamp('acknowledged_at')->nullable()->after('triggered_at');
            $table->foreignId('acknowledged_by')->nullable()->after('acknowledged_at')->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->after('resolved_at')->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable()->after('resolved_by');
        });
    }

    public function down(): void
    {
        Schema::table('sos_alerts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('acknowledged_by');
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn(['acknowledged_at', 'resolution_note']);
        });
    }
};
