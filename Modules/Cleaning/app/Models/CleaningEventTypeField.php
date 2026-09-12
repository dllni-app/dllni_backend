<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CleaningEventTypeField extends Model
{
    protected $fillable = ['cleaning_event_type_id', 'label', 'key', 'field_type', 'is_required', 'options', 'sort_order', 'is_active'];

    public function eventType(): BelongsTo
    {
        return $this->belongsTo(CleaningEventType::class, 'cleaning_event_type_id');
    }

    public function casts(): array
    {
        return ['is_required' => 'boolean', 'options' => 'array', 'sort_order' => 'integer', 'is_active' => 'boolean'];
    }
}
