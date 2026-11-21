<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

final class CleaningV3CatalogVersion extends Model
{
    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const RETIRED = 'retired';

    protected $table = 'cleaning_v3_catalog_versions';

    protected $fillable = ['title', 'notes'];

    public function items(): HasMany
    {
        return $this->hasMany(CleaningV3CatalogItem::class, 'catalog_version_id');
    }

    public function assertEditable(): void
    {
        if (! $this->exists || $this->status !== self::DRAFT) {
            throw new LogicException('Only a persisted draft catalog version can be modified.');
        }
    }

    protected static function booted(): void
    {
        self::creating(static function (self $version): void {
            if ($version->status && $version->status !== self::DRAFT) {
                throw new LogicException('V3 publishing is held pending approved catalog/pricing rules.');
            }
            $version->version_key ??= (string) Str::uuid();
            $version->created_by ??= auth()->id();
            $version->status = self::DRAFT;
        });

        self::updating(static function (self $version): void {
            if ($version->getRawOriginal('status') !== self::DRAFT || $version->status !== self::DRAFT
                || $version->isDirty(['published_by', 'published_at', 'published_digest', 'version_key'])) {
                throw new LogicException('Published catalog versions are immutable; V3 publication is held.');
            }
        });

        self::deleting(static function (self $version): void {
            throw new LogicException('Catalog history must not be deleted; retire using an audited operation.');
        });
    }

    protected function casts(): array
    {
        return ['published_at' => 'immutable_datetime'];
    }
}
