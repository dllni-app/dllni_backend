<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->timestamp('last_hour_team_prompted_at')->nullable();
            $table->string('last_hour_team_decision', 32)->nullable();
            $table->unsignedBigInteger('last_hour_team_worker_id')->nullable();
            $table->timestamp('last_hour_team_decided_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->dropColumn([
                'last_hour_team_prompted_at',
                'last_hour_team_decision',
                'last_hour_team_worker_id',
                'last_hour_team_decided_at',
            ]);
        });
    }
};
