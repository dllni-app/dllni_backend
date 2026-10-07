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
        Schema::table('workers', function (Blueprint $table): void {
            $table->json('preferred_work_types')->nullable()->after('preferred_work_type');
        });

        DB::table('workers')
            ->select(['id', 'preferred_work_type'])
            ->orderBy('id')
            ->chunkById(200, function ($workers): void {
                foreach ($workers as $worker) {
                    $legacy = (string) ($worker->preferred_work_type ?? 'cleaning');
                    $types = match ($legacy) {
                        'events' => ['events'],
                        'hourly' => ['hourly'],
                        'both' => ['cleaning', 'events'],
                        default => ['cleaning'],
                    };

                    DB::table('workers')
                        ->where('id', $worker->id)
                        ->update(['preferred_work_types' => json_encode($types)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table): void {
            $table->dropColumn('preferred_work_types');
        });
    }
};
