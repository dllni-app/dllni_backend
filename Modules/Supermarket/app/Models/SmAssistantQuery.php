<?php

declare(strict_types=1);

namespace Modules\Supermarket\Models;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Supermarket\Enums\SmAssistantInputMode;
use Modules\Supermarket\Traits\FilterQueries\SmAssistantQueryFilterQuery;

final class SmAssistantQuery extends Model
{
    use SmAssistantQueryFilterQuery;

    protected $table = 'sm_assistant_queries';

    protected $fillable = [
        'user_id',
        'store_id',
        'input_mode',
        'query_text',
        'voice_file_path',
        'matched_product_ids',
        'matched_recipe_id',
        'response_payload',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(SmStore::class, 'store_id');
    }

    public function matchedRecipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class, 'matched_recipe_id');
    }

    protected function casts(): array
    {
        return [
            'input_mode' => SmAssistantInputMode::class,
            'matched_product_ids' => 'array',
            'response_payload' => 'array',
        ];
    }
}
