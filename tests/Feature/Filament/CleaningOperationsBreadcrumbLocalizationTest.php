<?php

declare(strict_types=1);

use App\Filament\Clusters\CleaningOperationsCluster;

it('shows an Arabic cluster breadcrumb throughout cleaning operations', function (): void {
    expect(CleaningOperationsCluster::getNavigationLabel())->toBe('عمليات التنظيف');
    expect(CleaningOperationsCluster::getClusterBreadcrumb())->toBe('عمليات التنظيف');
});
