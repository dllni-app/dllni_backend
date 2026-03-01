<?php

declare(strict_types=1);

namespace App\Filament\Resources\RestaurantDisputes\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

final class RestaurantOrderDisputeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('ticket_number')->label('رقم التذكرة')->required()->disabled(),
                Select::make('status')
                    ->label('الحالة')
                    ->options([
                        'open' => __('restaurant_admin.enums.dispute_status.open'),
                        'under_review' => __('restaurant_admin.enums.dispute_status.under_review'),
                        'resolved' => __('restaurant_admin.enums.dispute_status.resolved'),
                        'closed' => __('restaurant_admin.enums.dispute_status.closed'),
                    ])
                    ->required(),
                Select::make('resolution_type')
                    ->label('القرار')
                    ->options([
                        'refund_full' => __('restaurant_admin.enums.resolution_type.refund_full'),
                        'refund_partial' => __('restaurant_admin.enums.resolution_type.refund_partial'),
                        'deduct_from_restaurant_balance' => __('restaurant_admin.enums.resolution_type.deduct_from_restaurant_balance'),
                        'close' => __('restaurant_admin.enums.resolution_type.close'),
                    ]),
                TextInput::make('refund_amount')->label('مبلغ الاسترداد')->numeric()->step('0.01'),
                TextInput::make('deduction_amount')->label('مبلغ الخصم')->numeric()->step('0.01'),
                Select::make('payout_hold_status')
                    ->label('حالة التجميد')
                    ->options([
                        'held' => __('restaurant_admin.enums.payout_hold_status.held'),
                        'released' => __('restaurant_admin.enums.payout_hold_status.released'),
                    ])
                    ->default('held')
                    ->required(),
                Textarea::make('description')->label('وصف النزاع')->rows(3),
                Textarea::make('admin_note')->label('ملاحظة الأدمن')->rows(3),
            ]);
    }
}
