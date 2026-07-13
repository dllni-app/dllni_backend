<?php

declare(strict_types=1);

use App\Filament\Resources\CleaningWorkerDeposits\Tables\CleaningTransactionsTable;
use App\Filament\Support\AdminUiFormatter;
use App\Models\CleaningDepositTransaction;
use App\Models\Worker;

it('exposes only the four supported financial transaction types', function (): void {
    app()->setLocale('en');

    expect(array_keys(CleaningTransactionsTable::typeOptions()))->toBe([
        'deposit',
        'debt',
        'settlement',
        'refund',
    ]);
});

it('maps internal references and legacy types to public financial labels', function (): void {
    app()->setLocale('en');

    expect(CleaningTransactionsTable::referenceLabel('admin_deposit'))->toBe('Admin deposit')
        ->and(CleaningTransactionsTable::referenceLabel('admin_settlement'))->toBe('Debt settlement')
        ->and(CleaningTransactionsTable::referenceLabel('admin_refund'))->toBe('Admin refund')
        ->and(CleaningTransactionsTable::referenceLabel('automatic_admin_commission:opaque-hash'))->toBe('Automatically recorded administration debt')
        ->and(CleaningTransactionsTable::referenceLabel('admin_fee_booking_42'))->toBe('Automatically recorded administration debt')
        ->and(CleaningTransactionsTable::referenceLabel(null))->toBe('—')
        ->and(CleaningTransactionsTable::referenceLabel('some_unknown_ref'))->toBe('some_unknown_ref')
        ->and(CleaningDepositTransaction::normalizePublicType('admin_fee'))->toBe('debt')
        ->and(CleaningDepositTransaction::normalizePublicType('withdrawal'))->toBe('refund')
        ->and(CleaningDepositTransaction::normalizePublicType('adjustment', 100))->toBe('deposit')
        ->and(CleaningDepositTransaction::normalizePublicType('adjustment', -100))->toBe('refund');
});

it('formats currency and numbers with latin digits regardless of locale', function (): void {
    app()->setLocale('ar');

    expect(AdminUiFormatter::formatNumber(12345.6, 1))->toBe('12,345.6')
        ->and(AdminUiFormatter::formatCurrency(50000))->toContain('50,000.00')
        ->and(AdminUiFormatter::formatCurrency(50000))->not->toMatch('/[\x{0660}-\x{0669}]/u');
});

it('exports only filtered transactions without exposing booking linkage', function (): void {
    app()->setLocale('en');

    $includedWorker = Worker::factory()->create(['first_name' => 'Included Worker']);
    $excludedWorker = Worker::factory()->create(['first_name' => 'Excluded Worker']);

    foreach ([$includedWorker, $excludedWorker] as $worker) {
        CleaningDepositTransaction::query()->create([
            'worker_id' => $worker->id,
            'type' => 'admin_fee',
            'amount' => 100,
            'balance_before' => 1000,
            'balance_after' => 900,
            'reference' => 'automatic_admin_commission:test-'.$worker->id,
        ]);
    }

    $rows = CleaningTransactionsTable::exportRows(
        CleaningDepositTransaction::query()->where('worker_id', $includedWorker->id),
    );

    expect($rows)->toHaveCount(1)
        ->and(array_values($rows[0]))->toContain('Included Worker')
        ->and(array_values($rows[0]))->toContain('Debt')
        ->and($rows[0])->not->toHaveKey('Booking');
});
