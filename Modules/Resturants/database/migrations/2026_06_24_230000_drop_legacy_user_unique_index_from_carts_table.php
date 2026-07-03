<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        return collect(Schema::getIndexes('carts'))
            ->filter(static fn (array $index): bool => $index['unique']
                && ! $index['primary']
                && $index['columns'] === ['user_id'])
            ->pluck('name')
            ->filter()
            ->map(static fn (string $name): string => $name)
            ->values()
            ->all();
    }

    private function indexExists(string $indexName): bool
    {
        return collect(Schema::getIndexes('carts'))
            ->contains(static fn (array $index): bool => $index['name'] === $indexName);
    }

    private function dropIndexIfExists(string $indexName): void
    {
        if (! $this->indexExists($indexName)) {
            return;
        }

        Schema::table('carts', function (Blueprint $table) use ($indexName): void {
            $table->dropIndex($indexName);
        });
    }
};
