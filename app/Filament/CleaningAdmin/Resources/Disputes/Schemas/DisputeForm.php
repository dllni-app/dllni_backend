<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\Disputes\Schemas;

use App\Enums\DisputeCategory;
use App\Enums\DisputeResolution;
use App\Enums\DisputeStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

final class DisputeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('ticket_number')->label('رقم التذكرة')->required(),
                TextInput::make('booking_id')->label('رقم الحجز')->numeric()->required(),
                TextInput::make('booking_type')->label('نوع الحجز')->required(),
                Textarea::make('description')->label('تفاصيل المشكلة')->rows(4),
                Select::make('category')
                    ->label('التصنيف')
                    ->options(collect(DisputeCategory::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all())
                    ->required(),
                Select::make('status')
                    ->label('الحالة')
                    ->options(collect(DisputeStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all())
                    ->required(),
                Select::make('resolution')
                    ->label('القرار')
                    ->options(collect(DisputeResolution::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()),
                Toggle::make('worker_earnings_frozen')
                    ->label('تجميد مستحقات العامل')
                    ->default(true),
            ]);
    }
}
