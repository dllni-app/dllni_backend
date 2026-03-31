<?php

declare(strict_types=1);

namespace Modules\User\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $phone
 * @property string $purpose
 * @property string $code_hash
 * @property int $attempts
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $consumed_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
final class UserOtp extends Model
{
    protected $table = 'user_otps';

    protected $fillable = [
        'phone',
        'purpose',
        'code_hash',
        'attempts',
        'expires_at',
        'consumed_at',
    ];

    protected $casts = [
        'id' => 'integer',
        'phone' => 'string',
        'purpose' => 'string',
        'code_hash' => 'string',
        'attempts' => 'integer',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
