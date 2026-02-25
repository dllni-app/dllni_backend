<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->timestamp('started_travel_at')->nullable()->after('work_finished_at');
        });

        DB::table('cleaning_bookings')->where('status', 'confirmed')->update(['status' => 'pending']);
        DB::table('cleaning_bookings')->where('status', 'worker_on_the_way')->update(['status' => 'worker_assigned']);
        DB::table('cleaning_bookings')->where('status', 'worker_arrived')->update(['status' => 'worker_assigned']);
    }

    public function down(): void
    {
        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->dropColumn('started_travel_at');
        });
    }
};
