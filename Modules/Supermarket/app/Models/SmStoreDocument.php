<?php

declare(strict_types=1);

namespace Modules\Supermarket\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Supermarket\Enums\SmDocumentType;
use Modules\Supermarket\Traits\FilterQueries\SmStoreDocumentFilterQuery;

final class SmStoreDocument extends Model
{
    use SmStoreDocumentFilterQuery;

    protected $table = 'sm_store_documents';

    protected $fillable = [
        'store_id',
        'document_type',
        'file_path',
        'verification_status',
        'rejection_reason',
        'verified_by_user_id',
        'verified_at',
        'expires_at',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(SmStore::class, 'store_id');
    }

    public function verifiedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'document_type' => SmDocumentType::class,
            'verified_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
