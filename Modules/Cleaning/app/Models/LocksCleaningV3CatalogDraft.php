<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use LogicException;

/** Serialize draft edits against the publisher on the owning version row. */
trait LocksCleaningV3CatalogDraft
{
    public function save(array $options = [])
    {
        return $this->getConnection()->transaction(function () use ($options) {
            $this->lockEditableCatalog();
            return parent::save($options);
        });
    }

    public function delete()
    {
        return $this->getConnection()->transaction(function () {
            $this->lockEditableCatalog();
            return parent::delete();
        });
    }

    private function lockEditableCatalog(): void
    {
        $foreignKey = $this instanceof CleaningV3CatalogItem ? 'catalog_version_id' : 'catalog_item_id';
        if ($this->exists && $this->isDirty($foreignKey)) {
            throw new LogicException('Catalog children cannot move between versions or items.');
        }
        $versionId = $this instanceof CleaningV3CatalogItem
            ? $this->catalog_version_id
            : CleaningV3CatalogItem::query()->whereKey($this->catalog_item_id)->value('catalog_version_id');
        $version = CleaningV3CatalogVersion::query()->whereKey($versionId)->lockForUpdate()->first();
        if ($version === null) {
            throw \Illuminate\Validation\ValidationException::withMessages([$foreignKey => 'A persisted catalog draft is required.']);
        }
        $version->assertEditable();
    }
}
