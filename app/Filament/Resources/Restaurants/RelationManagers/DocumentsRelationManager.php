<?php

declare(strict_types=1);

namespace App\Filament\Resources\Restaurants\RelationManagers;

use App\Enums\DocumentVerificationStatus;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
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
                TextColumn::make('file_path')->label('الملف')->limit(40)->placeholder('—')->toggleable(),
                TextColumn::make('expires_at')->label('تاريخ الانتهاء')->dateTime('Y-m-d')->placeholder('بدون انتهاء'),
                TextColumn::make('verified_at')->label('تم التحقق في')->dateTime('Y-m-d H:i')->placeholder('—'),
                TextColumn::make('verifiedBy.name')->label('راجعها')->placeholder('—'),
                TextColumn::make('rejection_reason')->label('سبب الرفض')->limit(50)->placeholder('—')->toggleable(),
                TextColumn::make('created_at')->label('أضيفت')->dateTime('Y-m-d H:i'),
            ])
            ->filters([
                SelectFilter::make('verification_status')->label('حالة التحقق')->options($verificationLabels),
                Filter::make('expired')->label('منتهية')->query(
                    fn ($query) => $query->whereNotNull('expires_at')->whereDate('expires_at', '<', today())
                ),
                Filter::make('expiring_soon')->label('تنتهي خلال 30 يوم')->query(
                    fn ($query) => $query->whereBetween('expires_at', [today(), today()->addDays(30)])
                ),
            ])
            ->recordActions([
                EditAction::make()
                    ->form([
                        Select::make('verification_status')
                            ->label('حالة التحقق')
                            ->options($verificationLabels)
                            ->required(),
                        DateTimePicker::make('expires_at')
                            ->label('تاريخ انتهاء الوثيقة')
                            ->nullable(),
                        Textarea::make('rejection_reason')
                            ->label('سبب الرفض')
                            ->rows(3)
                            ->maxLength(1000)
                            ->nullable(),
                    ])
                    ->mutateFormDataUsing(function (array $data): array {
                        $status = $data['verification_status'] ?? null;
                        $reason = mb_trim((string) ($data['rejection_reason'] ?? ''));

                        if ($status === DocumentVerificationStatus::Pending->value) {
                            $data['verified_at'] = null;
                            $data['verified_by_user_id'] = null;
                            $data['rejection_reason'] = null;

                            return $data;
                        }

                        if ($status === DocumentVerificationStatus::Rejected->value && $reason === '') {
                            throw ValidationException::withMessages([
                                'rejection_reason' => ['سبب الرفض مطلوب عند رفض الوثيقة.'],
                            ]);
                        }

                        $data['verified_at'] = now();
                        $data['verified_by_user_id'] = auth()->id();
                        $data['rejection_reason'] = $status === DocumentVerificationStatus::Rejected->value ? $reason : null;

                        return $data;
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
