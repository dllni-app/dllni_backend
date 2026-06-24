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
            SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns_list
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'carts'
              AND non_unique = 0
              AND index_name <> 'PRIMARY'
            GROUP BY index_name
            HAVING columns_list = 'user_id'
        SQL);

        return array_map(static fn (object $row): string => (string) $row->index_name, $rows);
    }

    private function indexExists(string $indexName): bool
    {
        $exists = DB::selectOne(<<<'SQL'
            SELECT 1 AS exists_flag
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'carts'
              AND index_name = ?
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
