<?php

declare(strict_types=1);

namespace Modules\Supermarket\Traits\FilterQueries;

use Modules\Supermarket\Models\SmStoreDocument;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait SmStoreDocumentFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(SmStoreDocument::class)
            ->allowedFilters([
                AllowedFilter::exact('storeId', 'store_id'),
                AllowedFilter::exact('documentType', 'document_type'),
                AllowedFilter::exact('verificationStatus', 'verification_status'),
            ])
            ->allowedSorts([
                AllowedSort::field('documentType', 'document_type'),
                AllowedSort::field('verificationStatus', 'verification_status'),
                AllowedSort::field('createdAt', 'created_at'),
            ])
            ->defaultSort('-created_at');
    }
}
