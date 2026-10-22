<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLE = 'cleaning_transaction_admission_locks';

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());
        $driver = $connection->getDriverName();
        $table = $connection->getQueryGrammar()->wrapTable(self::TABLE);

        $sql = match ($driver) {
            'sqlite' => "CREATE TABLE {$table} (id INTEGER NOT NULL, CONSTRAINT ctad_singleton_ck CHECK (id = 1), PRIMARY KEY (id))",
            'mysql' => "CREATE TABLE {$table} (id TINYINT UNSIGNED NOT NULL, CONSTRAINT ctad_singleton_ck CHECK (id = 1), PRIMARY KEY (id)) ENGINE=InnoDB",
            default => throw new RuntimeException("Cleaning transaction admission does not support the [{$driver}] driver."),
        };

        $connection->statement($sql);
        $connection->table(self::TABLE)->insert(['id' => 1]);
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());
        $table = $connection->getQueryGrammar()->wrapTable(self::TABLE);

        $connection->statement("DROP TABLE {$table}");
    }
};
