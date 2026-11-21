<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WorkerComplianceReviewEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'worker_compliance_review_events';

    protected $guarded = ['id'];

    public function complianceCase(): BelongsTo
    {
        return $this->belongsTo(WorkerComplianceReviewCase::class, 'case_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function casts(): array
    {
        return [
            'worker_response_opportunity_at' => 'datetime',
            'created_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
