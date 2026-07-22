<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'pending_home_address' => function (Blueprint $table): void {
                $table->string('pending_home_address')->nullable()->after('home_longitude');
            },
            'pending_home_latitude' => function (Blueprint $table): void {
                $table->decimal('pending_home_latitude', 10, 8)->nullable()->after('pending_home_address');
            },
            'pending_home_longitude' => function (Blueprint $table): void {
                $table->decimal('pending_home_longitude', 11, 8)->nullable()->after('pending_home_latitude');
            },
            'home_location_status' => function (Blueprint $table): void {
                $table->string('home_location_status', 20)
                    ->default('approved')
                    ->after('pending_home_longitude');
            },
            'home_location_rejection_reason' => function (Blueprint $table): void {
                $table->text('home_location_rejection_reason')->nullable()->after('home_location_status');
            },
        ];

        foreach ($columns as $column => $definition) {
            if (Schema::hasColumn('workers', $column)) {
                continue;
            }

            Schema::table('workers', $definition);
        }
    }

    public function down(): void
    {
        $columns = [
            'pending_home_address',
            'pending_home_latitude',
            'pending_home_longitude',
            'home_location_status',
            'home_location_rejection_reason',
        ];

        $existing = array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasColumn('workers', $column),
        ));

        if ($existing === []) {
            return;
        }

        Schema::table('workers', function (Blueprint $table) use ($existing): void {
            $table->dropColumn($existing);
        });
    }
};
