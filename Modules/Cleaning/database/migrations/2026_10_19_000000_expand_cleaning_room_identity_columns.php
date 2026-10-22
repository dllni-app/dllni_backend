<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, array{column:string, previous:int, next:int, nullable:bool}> */
    private const COLUMNS = [
        'cleaning_booking_room_periods' => [
            'room_type' => ['column' => 'room_type', 'previous' => 32, 'next' => 64, 'nullable' => false],
            'client_key' => ['column' => 'client_key', 'previous' => 64, 'next' => 68, 'nullable' => false],
        ],
        'cleaning_material_quantity_rules' => [
            'room_type' => ['column' => 'room_type', 'previous' => 32, 'next' => 64, 'nullable' => true],
        ],
        'cleaning_material_type_quantity_rules' => [
            'room_type' => ['column' => 'room_type', 'previous' => 32, 'next' => 64, 'nullable' => true],
        ],
        'cleaning_booking_rooms' => [
            'client_key' => ['column' => 'client_key', 'previous' => 64, 'next' => 68, 'nullable' => true],
        ],
        'cleaning_room_schedules' => [
            'schedule_key' => ['column' => 'schedule_key', 'previous' => 64, 'next' => 77, 'nullable' => false],
        ],
    ];

    public function up(): void
    {
        $this->changeLengths('next');
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());
        $lengthFunction = match ($connection->getDriverName()) {
            'sqlite' => 'LENGTH',
            'mysql' => 'CHAR_LENGTH',
            default => throw new RuntimeException('Cleaning room identity migration supports only SQLite and MySQL.'),
        };
        $violations = [];

        // Check every affected column before any narrowing DDL is attempted.
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::connection($this->getConnection())->hasTable($table)) {
                throw new RuntimeException("Cannot safely narrow missing table [{$table}].");
            }
            foreach ($columns as $definition) {
                $column = $definition['column'];
                if (! Schema::connection($this->getConnection())->hasColumn($table, $column)) {
                    throw new RuntimeException("Cannot safely narrow missing column [{$table}.{$column}].");
                }
                $wrappedColumn = $connection->getQueryGrammar()->wrap($column);
                if ($connection->table($table)->whereNotNull($column)
                    ->whereRaw("{$lengthFunction}({$wrappedColumn}) > ?", [$definition['previous']])->exists()) {
                    $violations[] = "{$table}.{$column}";
                }
            }
        }

        if ($violations !== []) {
            throw new RuntimeException('Cannot safely narrow room identity columns containing values over the previous width: '.implode(', ', $violations).'.');
        }

        $this->changeLengths('previous');
    }

    private function changeLengths(string $length): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::connection($this->getConnection())->hasTable($table)) {
                throw new RuntimeException("Cannot change missing table [{$table}].");
            }
            Schema::connection($this->getConnection())->table($table, static function (Blueprint $blueprint) use ($columns, $length): void {
                foreach ($columns as $definition) {
                    $width = $definition[$length];
                    $column = $blueprint->string($definition['column'], $width);
                    if ($definition['nullable']) {
                        $column->nullable();
                    }
                    $column->change();
                }
            });
        }
    }
};
