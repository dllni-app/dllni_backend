<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<int, array{start:int,end:int,sort:int}>
     */
    private array $ranges = [
        ['start' => 0, 'end' => 15, 'sort' => 1],
        ['start' => 16, 'end' => 30, 'sort' => 2],
        ['start' => 31, 'end' => 45, 'sort' => 3],
        ['start' => 46, 'end' => 60, 'sort' => 4],
        ['start' => 61, 'end' => 75, 'sort' => 5],
        ['start' => 76, 'end' => 90, 'sort' => 6],
    ];

    public function up(): void
    {
        Schema::create('cleaning_extended_time_prices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('start_minutes');
            $table->unsignedSmallInteger('end_minutes');
            $table->decimal('price', 10, 2)->default(0);
            $table->unsignedTinyInteger('sort_order');
            $table->timestamps();

            $table->unique(['start_minutes', 'end_minutes'], 'cleaning_extended_time_prices_range_unique');
        });

        $now = now();

        foreach ($this->ranges as $range) {
            DB::table('cleaning_extended_time_prices')->updateOrInsert(
                [
                    'start_minutes' => $range['start'],
                    'end_minutes' => $range['end'],
                ],
                [
                    'price' => 0,
                    'sort_order' => $range['sort'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_extended_time_prices');
    }
};
