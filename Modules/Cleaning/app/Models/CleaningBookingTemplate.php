<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;

final class CleaningBookingTemplate extends Model
{
    protected $fillable = [
        'customer_id', 'name', 'schema_version', 'inputs', 'revision',
        'creation_key', 'creation_hash',
    ];

    protected function casts(): array
    {
        return ['inputs' => 'array', 'revision' => 'integer', 'schema_version' => 'integer'];
    }
}
