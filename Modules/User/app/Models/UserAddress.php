<?php

declare(strict_types=1);

namespace Modules\User\Models;

use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\UserAddressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $label
 * @property string|null $mobile
 * @property string|null $city
 * @property string|null $neighborhood
 * @property string|null $street
 * @property string|null $building
 * @property string|null $floor
 * @property string|null $directions
 * @property string|null $latitude
 * @property string|null $longitude
 * @property bool $is_default
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
final class UserAddress extends Model
{
    /** @use HasFactory<UserAddressFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'label',
        'mobile',
        'city',
        'neighborhood',
        'street',
        'building',
        'floor',
        'directions',
        'latitude',
        'longitude',
        'is_default',
    ];

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'integer',
            'user_id' => 'integer',
            'label' => 'string',
            'mobile' => 'string',
            'city' => 'string',
            'neighborhood' => 'string',
            'street' => 'string',
            'building' => 'string',
            'floor' => 'string',
            'directions' => 'string',
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'is_default' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolveRouteBinding($value, $field = null): self
    {
        $field ??= $this->getRouteKeyName();

        /** @var self|null $address */
        $address = self::query()
            ->where($field, $value)
            ->where('user_id', auth()->id())
            ->first();

        if ($address === null) {
            abort(404);
        }

        return $address;
    }

    protected static function newFactory(): UserAddressFactory
    {
        return UserAddressFactory::new();
    }
}
