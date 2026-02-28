<?php

declare(strict_types=1);

namespace App\Filament\Resources\Restaurants\RelationManagers;

use App\Enums\DocumentVerificationStatus;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Resturants\Enums\RestaurantDocumentType;

final class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'الوثائق والتحقق';

    public function table(Table $table): Table
    {
        $docTypeLabels = [
            RestaurantDocumentType::Identity->value => __('restaurant_admin.enums.document_type.identity'),
            RestaurantDocumentType::CommercialRegistration->value => __('restaurant_admin.enums.document_type.commercial_registration'),
            RestaurantDocumentType::HealthCertificate->value => __('restaurant_admin.enums.document_type.health_certificate'),
            RestaurantDocumentType::Other->value => __('restaurant_admin.enums.document_type.other'),
        ];
        $verificationLabels = [
            DocumentVerificationStatus::Pending->value => __('restaurant_admin.enums.verification_status.pending'),
            DocumentVerificationStatus::Approved->value => __('restaurant_admin.enums.verification_status.approved'),
            DocumentVerificationStatus::Rejected->value => __('restaurant_admin.enums.verification_status.rejected'),
        ];

        return $table
            ->columns([
                TextColumn::make('document_type')
                    ->label('نوع الوثيقة')
                    ->formatStateUsing(function ($state) use ($docTypeLabels): string {
                        $value = $state?->value ?? $state;

                        return $docTypeLabels[$value ?? ''] ?? (string) $value;
                    }),
                TextColumn::make('verification_status')
                    ->label('حالة التحقق')
                    ->formatStateUsing(function ($state) use ($verificationLabels): string {
                        $value = $state?->value ?? $state;

                        return $verificationLabels[$value ?? ''] ?? (string) $value;
                    })
                    ->badge(),
                TextColumn::make('file_path')->label('الملف')->limit(40)->placeholder('—'),
                TextColumn::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i'),
            ])
            ->recordActions([
                EditAction::make()
                    ->form([
                        Select::make('verification_status')
                            ->label('حالة التحقق')
                            ->options($verificationLabels)
                            ->required(),
                    ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
