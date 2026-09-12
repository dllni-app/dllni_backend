<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->string('worker_scope', 32)->nullable()->after('assignment_mode');
            $table->json('specific_worker_ids')->nullable()->after('worker_scope');
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->dropColumn(['worker_scope', 'specific_worker_ids']);
        });
    }
};
