<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryDisputes\Tables;

use App\Enums\DisputeCategory;
use App\Enums\DisputeStatus;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Delivery\Models\DeliveryCompany;

final class DeliveryDisputesTable
{
    public static function configure(Table $table):Table
    {
        return $table
            ->columns([
                TextColumn::make('ticket_number')->label('رقم النزاع')->searchable()->sortable(),
                TextColumn::make('booking.order_number')->label('طلب التوصيل')->searchable()->placeholder('—'),
                TextColumn::make('booking.company.name')->label('الشركة')->searchable()->placeholder('—'),
                TextColumn::make('booking.driver.first_name')->label('المندوب')->searchable()->placeholder('—'),
                TextColumn::make('booking.customer_name')->label('العميل')->searchable()->placeholder('—'),
                TextColumn::make('category')->label('الفئة')->badge()->formatStateUsing(fn($state):string=>$state?->label()??'—'),
                TextColumn::make('status')->label('الحالة')->badge()->formatStateUsing(fn($state):string=>$state?->label()??'—'),
                TextColumn::make('created_at')->label('تاريخ الإنشاء')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options(
                    collect(DisputeStatus::cases())->mapWithKeys(fn(DisputeStatus $s):array=>[$s->value=>$s->label()])->all()
                ),
                SelectFilter::make('category')->label('الفئة')->options(
                    collect(DisputeCategory::cases())->mapWithKeys(fn(DisputeCategory $c):array=>[$c->value=>$c->label()])->all()
                ),
                Filter::make('company_id')->label('شركة التوصيل')->form([
                    Select::make('company_id')->label('الشركة')
                        ->options(fn():array=>DeliveryCompany::query()->orderBy('name')->pluck('name','id')->all())
                        ->searchable(),
                ])->query(fn(Builder $query,array $data):Builder=>$query->when(
                    $data['company_id']??null,
                    fn(Builder $q,$companyId):Builder=>$q->whereHas('booking',fn(Builder $order):Builder=>$order->where('company_id',$companyId))
                )),
            ])
            ->modifyQueryUsing(fn(Builder $query):Builder=>$query->with(['booking.company','booking.driver','messages']))
            ->recordActions([ViewAction::make()])
            ->defaultSort('created_at','desc');
    }
}
