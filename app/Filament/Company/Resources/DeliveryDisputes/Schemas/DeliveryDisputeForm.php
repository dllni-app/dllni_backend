<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryDisputes\Schemas;

use App\Enums\DisputeCategory;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Services\DeliveryCompanyContextService;

final class DeliveryDisputeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('delivery_company.disputes.sections.ticket'))
                    ->schema([
                        Select::make('booking_id')
                            ->label(__('delivery_company.disputes.fields.order_number'))
                            ->options(fn (): array => self::companyOrderOptions())
                            ->searchable()
                            ->required(),
                        Select::make('category')
                            ->label(__('delivery_company.disputes.fields.category'))
                            ->options(collect(DisputeCategory::cases())->mapWithKeys(
                                fn (DisputeCategory $category): array => [$category->value => $category->label()],
                            )->all())
                            ->required(),
                        Textarea::make('description')
                            ->label(__('delivery_company.disputes.fields.description'))
                            ->rows(4)
                            ->required()
                            ->maxLength(5000),
                    ])
                    ->columns(1),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function companyOrderOptions(): array
    {
        $companyId = app(DeliveryCompanyContextService::class)->companyIdForUser(auth()->user());

        return DeliveryOrder::query()
            ->where('company_id', $companyId)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->mapWithKeys(fn (DeliveryOrder $order): array => [
                $order->id => $order->order_number.' — '.$order->customer_name,
            ])
            ->all();
    }
}
