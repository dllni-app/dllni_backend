<?php

declare(strict_types=1);

namespace Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DeliveryCompanyStaff extends Model
{
    protected $table = 'delivery_company_staff';

    protected $fillable = ['company_id', 'user_id', 'role_key', 'is_active'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(DeliveryCompany::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
