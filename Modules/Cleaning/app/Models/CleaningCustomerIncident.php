<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;

final class CleaningCustomerIncident extends Model
{
    protected $table = 'cleaning_customer_incidents';

    protected $fillable = [
        'booking_id', 'visit_key', 'customer_id', 'reporter_user_id',
        'reporter_role', 'categories', 'description',
    ];

    protected $casts = ['categories' => 'array'];
}
