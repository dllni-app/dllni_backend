<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;

final class CleaningExtendedTimePrice extends Model
{
    /**
     * @var array<int, array{start:int,end:int,sort:int}>
     */
    public const FIXED_RANGES = [
        ['start' => 0, 'end' => 15, 'sort' => 1],
        ['start' => 16, 'end' => 30, 'sort' => 2],
        ['start' => 31, 'end' => 45, 'sort' => 3],
        ['start' => 46, 'end' => 60, 'sort' => 4],
        ['start' => 61, 'end' => 75, 'sort' => 5],
        ['start' => 76, 'end' => 90, 'sort' => 6],
    ];

    protected $fillable = [
        'price',
    ];

    public function casts(): array
    {
        return [
            'start_minutes' => 'integer',
            'end_minutes' => 'integer',
            'price' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function label(): string
    {
        return "{$this->start_minutes} - {$this->end_minutes} minutes";
    }
}
