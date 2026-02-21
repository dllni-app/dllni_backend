<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use Illuminate\Support\Facades\DB;
use Modules\Supermarket\Data\SmStoreDocumentData;
use Modules\Supermarket\Models\SmStoreDocument;

final class SmStoreDocumentService
{
    public function store(SmStoreDocumentData $data): SmStoreDocument
    {
        return DB::transaction(static function () use ($data) {
            $document = SmStoreDocument::create($data->onlyModelAttributes());

            return $document;
        });
    }

    public function update(SmStoreDocumentData $data, SmStoreDocument $document): SmStoreDocument
    {
        return DB::transaction(static function () use ($data, $document) {
            tap($document)->update($data->onlyModelAttributes());

            return $document;
        });
    }
}
