<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\FilterQueries\UserFilterQuery;
use Carbon\CarbonInterface;
use App\Traits\HasMediaConversions;
use Database\Factories\UserFactory;
use Illuminate\Notifications\Notifiable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Scout\Searchable;
use Spatie\MediaLibrary\HasMedia;

/**
 * @property-read int $id
 * @property-read string $name
 * @property-read string $email
 * @property-read CarbonInterface|null $email_verified_at
 * @property-read string $password
 * @property-read string|null $remember_token
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 */
final class User extends Authenticatable implements MustVerifyEmail , HasMedia
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable , HasMediaConversions , Searchable , HasApiTokens;

    protected $fillable = [
        'name',
        'email',
        'email_verified_at',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'integer',
            'name' => 'string',
            'email' => 'string',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'remember_token' => 'string',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return array_merge($this->toArray(),[
            'id' => (string) $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'created_at' => $this->created_at->timestamp,
        ]);
    }
}
