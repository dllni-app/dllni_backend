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
        foreach ($this->userOnlyUniqueIndexes() as $indexName) {
            $this->dropIndexIfExists($indexName);
        }

        if (Schema::hasColumn('carts', 'restaurant_id') && ! $this->indexExists('carts_user_restaurant_unique')) {
            Schema::table('carts', function (Blueprint $table): void {
                $table->unique(['user_id', 'restaurant_id'], 'carts_user_restaurant_unique');
            });
        }
    }

    public function down(): void
    {
        // Intentionally do not recreate the legacy user-only unique index.
        // The application now supports multiple restaurant carts per user.
    }

    /**
     * @return array<int, string>
     */
    private function userOnlyUniqueIndexes(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT INDEX_NAME AS index_name, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_list
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'carts'
              AND NON_UNIQUE = 0
              AND INDEX_NAME <> 'PRIMARY'
            GROUP BY INDEX_NAME
            HAVING columns_list = 'user_id'
        SQL);

        return array_values(array_filter(array_map(
            static fn (object $row): ?string => isset($row->index_name) ? (string) $row->index_name : null,
            $rows,
        )));
    }

    private function indexExists(string $indexName): bool
    {
        $exists = DB::selectOne(<<<'SQL'
            SELECT 1 AS exists_flag
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'carts'
              AND INDEX_NAME = ?
            LIMIT 1
        SQL, [$indexName]);

        return $exists !== null;
    }

    private function dropIndexIfExists(string $indexName): void
    {
        if (! $this->indexExists($indexName)) {
            return;
        }

        $safeIndexName = str_replace('`', '``', $indexName);
        DB::statement("ALTER TABLE `carts` DROP INDEX `{$safeIndexName}`");
    }
};
