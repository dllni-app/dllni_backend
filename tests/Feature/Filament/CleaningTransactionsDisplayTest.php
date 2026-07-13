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

it('formats financial amounts as Latin integers regardless of locale', function (): void {
    app()->setLocale('ar');

    $formatted = AdminUiFormatter::formatCurrency(50000.75, 0);

    expect(AdminUiFormatter::formatNumber(12345.6, 1))->toBe('12,345.6')
        ->and($formatted)->toContain('50,001')
        ->and($formatted)->not->toContain('.')
        ->and($formatted)->not->toMatch('/[\x{0660}-\x{0669}]/u');
});

it('exports only filtered transactions without exposing booking linkage', function (): void {
    app()->setLocale('en');

    $includedWorker = Worker::factory()->create(['first_name' => 'Included Worker']);
    $excludedWorker = Worker::factory()->create(['first_name' => 'Excluded Worker']);

    foreach ([$includedWorker, $excludedWorker] as $worker) {
        CleaningDepositTransaction::query()->create([
            'worker_id' => $worker->id,
            'type' => 'debt',
            'amount' => 100.40,
            'balance_before' => 1000.20,
            'balance_after' => 899.80,
            'reference' => CleaningDepositTransaction::AUTOMATIC_ADMIN_DEBT_REFERENCE_PREFIX.'test-'.$worker->id,
        ]);
    }

    $rows = CleaningTransactionsTable::exportRows(
        CleaningDepositTransaction::query()->where('worker_id', $includedWorker->id),
    );

    expect($rows)->toHaveCount(1)
        ->and(array_values($rows[0]))->toContain('Included Worker')
        ->and(array_values($rows[0]))->toContain('Debt')
        ->and($rows[0][__('cleaning_admin.transactions.fields.amount')])->toBe(100)
        ->and($rows[0][__('cleaning_admin.transactions.fields.balance_before')])->toBe(1000)
        ->and($rows[0][__('cleaning_admin.transactions.fields.balance_after')])->toBe(900)
        ->and($rows[0])->not->toHaveKey('Booking');
});
