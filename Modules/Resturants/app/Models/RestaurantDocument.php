<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use App\Enums\DocumentVerificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Resturants\Enums\RestaurantDocumentType;
use Modules\Resturants\Traits\FilterQueries\RestaurantDocumentFilterQuery;

final class RestaurantDocument extends Model
{
    use RestaurantDocumentFilterQuery;

    protected $fillable = [
        'restaurant_id',
        'document_type',
        'verification_status',
        'file_path',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    protected function casts(): array
    {
        return [
            'document_type' => RestaurantDocumentType::class,
            'verification_status' => DocumentVerificationStatus::class,
        ];
    }
}
