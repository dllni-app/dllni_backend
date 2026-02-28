<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmStoreDocuments\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Modules\Supermarket\Enums\SmDocumentType;

final class SmStoreDocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        $documentTypeOptions = collect(SmDocumentType::cases())->mapWithKeys(
            fn (SmDocumentType $c) => [$c->value => __('supermarket_admin.enums.document_type.'.$c->value)]
        )->all();

        $verificationOptions = [
            'pending' => __('supermarket_admin.enums.verification_status.pending'),
            'approved' => __('supermarket_admin.enums.verification_status.approved'),
            'rejected' => __('supermarket_admin.enums.verification_status.rejected'),
        ];

        return $schema
            ->components([
                Select::make('document_type')
                    ->label(__('supermarket_admin.form.document_type'))
                    ->options($documentTypeOptions)
                    ->required()
                    ->native(false),
                Select::make('verification_status')
                    ->label(__('supermarket_admin.form.status'))
                    ->options($verificationOptions)
                    ->required()
                    ->native(false),
                Textarea::make('rejection_reason')
                    ->label(__('supermarket_admin.form.rejection_reason'))
                    ->rows(3)
                    ->visible(fn ($get) => $get('verification_status') === 'rejected'),
            ]);
    }
}
