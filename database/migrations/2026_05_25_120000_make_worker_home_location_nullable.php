<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE workers MODIFY home_address VARCHAR(255) NULL');
            DB::statement('ALTER TABLE workers MODIFY home_latitude DECIMAL(10, 8) NULL');
            DB::statement('ALTER TABLE workers MODIFY home_longitude DECIMAL(11, 8) NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE workers ALTER COLUMN home_address DROP NOT NULL');
            DB::statement('ALTER TABLE workers ALTER COLUMN home_latitude DROP NOT NULL');
            DB::statement('ALTER TABLE workers ALTER COLUMN home_longitude DROP NOT NULL');
        }
    }

    public function down(): void {}
};
