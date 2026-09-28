<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use App\Enums\DocumentVerificationStatus;
use App\Models\User;
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
        'verified_at',
        'verified_by_user_id',
        'expires_at',
        'rejection_reason',
        'file_path',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'document_type' => RestaurantDocumentType::class,
            'verification_status' => DocumentVerificationStatus::class,
            'verified_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
