<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $indexName = Schema::getConnection()->getDriverName() === 'sqlite'
            ? 'clean_book_special_services_session_unique'
            : 'clean_book_special_pivot_session_unique';

        Schema::table('cleaning_booking_special_services', function (Blueprint $table): void {
            $table->index('cleaning_booking_id', 'clean_book_special_booking_idx');
        });

        Schema::table('cleaning_booking_special_services', function (Blueprint $table) use ($indexName): void {
            $table->dropUnique('clean_book_special_unique');
            $table->unique(
                ['cleaning_booking_id', 'cleaning_special_service_id', 'cleaning_booking_session_id'],
                $indexName
            );
        });
    }

    public function down(): void
    {
        $tableName = 'cleaning_booking_special_services';
        $indexNames = [
            'clean_book_special_pivot_session_unique',
            'clean_book_special_services_session_unique',
            'clean_book_special_session_unique',
        ];

        foreach ($indexNames as $indexName) {
            if (Schema::hasIndex($tableName, $indexName)) {
                Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                    $table->dropUnique($indexName);
                });
                break;
            }
        }

        Schema::table($tableName, function (Blueprint $table): void {
            $table->unique(
                ['cleaning_booking_id', 'cleaning_special_service_id'],
                'clean_book_special_unique'
            );
        });
    }
};