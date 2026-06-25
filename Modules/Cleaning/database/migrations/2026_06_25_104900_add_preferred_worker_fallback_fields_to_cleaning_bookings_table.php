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
            $table->boolean('converted_from_preferred_worker')->default(false)->after('preferred_worker_id');
            $table->timestamp('converted_from_preferred_worker_at')->nullable()->after('converted_from_preferred_worker');
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->dropColumn('converted_from_preferred_worker');
            $table->dropColumn('converted_from_preferred_worker_at');
        });
    }
};
