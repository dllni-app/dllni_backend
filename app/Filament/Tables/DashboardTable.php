<?php

declare(strict_types=1);

namespace App\Filament\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table as BaseTable;

final class DashboardTable extends BaseTable
{
    /**
     * @param  array<\Filament\Tables\Columns\Column | \Filament\Tables\Columns\ColumnGroup | \Filament\Tables\Columns\Layout\Component>  $components
     */
    public function columns(array $components): static
    {
        parent::columns($components);

        $createdAtColumn = $this->getColumn('created_at');

        if ($createdAtColumn !== null) {
            $createdAtColumn->label('تاريخ الإنشاء');

            return $this;
        }

        return $this->pushColumns([
            TextColumn::make('created_at')
                ->label('تاريخ الإنشاء')
                ->dateTime('d/m/Y H:i')
                ->placeholder('—')
                ->toggleable(),
        ]);
    }
}
