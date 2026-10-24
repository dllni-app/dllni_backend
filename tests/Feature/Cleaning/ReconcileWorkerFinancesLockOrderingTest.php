<?php

declare(strict_types=1);

use App\Models\CleaningDepositTransaction;
use App\Models\CleaningWorkerDeposit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->reconciliationOriginalDefault = DB::getDefaultConnection();
    $this->reconciliationOriginalConfigDefault = config('database.default');
    $this->reconciliationConnectionName = 'cleaning_reconcile_fixture_'.Str::uuid()->toString();
    $this->reconciliationOwnedConfigWasPresent = array_key_exists(
        $this->reconciliationConnectionName,
        (array) config('database.connections'),
    );
    $this->reconciliationOriginalOwnedConfig = config('database.connections.'.$this->reconciliationConnectionName);

    $source = DB::connection($this->reconciliationOriginalDefault);
    if ($source->getDriverName() !== 'sqlite' || $source->getConfig('database') !== ':memory:') {
        throw new RuntimeException('Reconciliation feature tests require the isolated phpunit SQLite in-memory source schema.');
    }
    $this->reconciliationOriginalFrameworkConnection = $source;
    $this->reconciliationOriginalFrameworkDepth = $source->transactionLevel();
    $this->reconciliationOriginalTransactionManager = app('db.transactions');

    $tables = [
        'cleaning_deposit_transactions',
        'cleaning_transaction_admission_locks',
        'cleaning_worker_deposits',
    ];
    $placeholders = implode(', ', array_fill(0, count($tables), '?'));
    $schema = $source->select(
        "SELECT type, name, tbl_name, sql FROM sqlite_master WHERE type IN ('table', 'index') AND tbl_name IN ({$placeholders}) AND sql IS NOT NULL ORDER BY CASE WHEN type = 'table' THEN 0 ELSE 1 END, name",
        $tables,
    );
    $schemaTables = collect($schema)
        ->where('type', 'table')
        ->pluck('tbl_name')
        ->all();
    if (array_diff($tables, $schemaTables) !== []) {
        throw new RuntimeException('The migrated source schema is missing a reconciliation table.');
    }

    config([
        'database.default' => $this->reconciliationConnectionName,
        'database.connections.'.$this->reconciliationConnectionName => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ],
    ]);
    DB::purge($this->reconciliationConnectionName);
    DB::setDefaultConnection($this->reconciliationConnectionName);

    $owned = DB::connection($this->reconciliationConnectionName);
    foreach ($schema as $definition) {
        $owned->unprepared($definition->sql);
    }
    $owned->table('cleaning_transaction_admission_locks')->insert(['id' => 1]);
});

afterEach(function (): void {
    $connectionName = $this->reconciliationConnectionName;
    $owned = DB::getConnections()[$connectionName] ?? null;
    if ($owned !== null) {
        $pdo = $owned->getRawPdo();
        if ($owned->transactionLevel() > 0 && $pdo instanceof PDO && $pdo->inTransaction()) {
            $owned->rollBack(0);
        }

        DB::disconnect($connectionName);
        DB::purge($connectionName);
    }

    DB::setDefaultConnection($this->reconciliationOriginalDefault);
    config(['database.default' => $this->reconciliationOriginalConfigDefault]);

    $connections = (array) config('database.connections');
    if ($this->reconciliationOwnedConfigWasPresent) {
        $connections[$connectionName] = $this->reconciliationOriginalOwnedConfig;
    } else {
        unset($connections[$connectionName]);
    }
    config(['database.connections' => $connections]);

    expect(DB::getDefaultConnection())->toBe($this->reconciliationOriginalDefault)
        ->and(config('database.default'))->toBe($this->reconciliationOriginalConfigDefault)
        ->and(app('db.transactions'))->toBe($this->reconciliationOriginalTransactionManager)
        ->and(DB::getConnections()[$this->reconciliationOriginalDefault] ?? null)->toBe($this->reconciliationOriginalFrameworkConnection)
        ->and($this->reconciliationOriginalFrameworkConnection->transactionLevel())->toBe($this->reconciliationOriginalFrameworkDepth);
});

function reconciliationQaWorkerId(): int
{
    static $workerId = 910000;

    return ++$workerId;
}

function reconciliationQaAccount(int $workerId, array $overrides = []): CleaningWorkerDeposit
{
    $now = now();
    DB::table('cleaning_worker_deposits')->insert(array_merge([
        'worker_id' => $workerId,
        'current_balance' => 1,
        'debt_balance' => 2,
        'deposited_total' => 8,
        'withdrawn_total' => 0,
        'admin_revenue_withdrawn_total' => 0,
        'minimum_required' => 60,
        'max_negative_balance' => 999,
        'is_active' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));

    return CleaningWorkerDeposit::query()->where('worker_id', $workerId)->firstOrFail();
}

function reconciliationQaEntry(int $workerId, string $type, int $amount): void
{
    static $sequence = 0;
    $now = now();
    DB::table('cleaning_deposit_transactions')->insert([
        'worker_id' => $workerId,
        'type' => $type,
        'amount' => $amount,
        'debt_settled_amount' => 0,
        'admin_revenue_withdrawn_amount' => 0,
        'balance_before' => 0,
        'balance_after' => 0,
        'debt_balance_before' => 0,
        'debt_balance_after' => 0,
        'reference' => 'qa-reconcile-'.$workerId.'-'.$type.'-'.$amount.'-'.(++$sequence),
        'notes' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function reconciliationApply(array $options): int
{
    try {
        return Artisan::call('cleaning:reconcile-worker-finances', $options);
    } catch (Throwable) {
        return 1;
    }
}

function reconciliationRecordRootQueries(array &$queries): void
{
    $connectionName = DB::getDefaultConnection();
    $connection = DB::connection($connectionName);
    $baselineDepth = 0;

    DB::listen(static function (QueryExecuted $event) use (&$queries, $connectionName, $connection, $baselineDepth): void {
        if ($event->connectionName === $connectionName && $connection->transactionLevel() > $baselineDepth) {
            $queries[] = mb_strtolower($event->sql);
        }
    });
}

it('keeps dry-run advisory and applies only after sentinel, account lock, and fresh ledger read', function (): void {
    $workerId = reconciliationQaWorkerId();
    $account = reconciliationQaAccount($workerId);
    reconciliationQaEntry($workerId, 'deposit', 150);
    reconciliationQaEntry($workerId, 'commission', 40);
    DB::table('cleaning_transaction_admission_locks')->delete();

    expect(Artisan::call('cleaning:reconcile-worker-finances', ['--worker' => $workerId]))->toBe(0)
        ->and((float) $account->fresh()->current_balance)->toBe(1.0)
        ->and((float) $account->fresh()->minimum_required)->toBe(60.0)
        ->and(DB::table('cleaning_deposit_transactions')->count())->toBe(2)
        ->and(DB::table('cleaning_transaction_admission_locks')->count())->toBe(0);

    DB::table('cleaning_transaction_admission_locks')->insert(['id' => 1]);
    $rootQueries = [];
    reconciliationRecordRootQueries($rootQueries);

    expect(Artisan::call('cleaning:reconcile-worker-finances', [
        '--worker' => $workerId,
        '--apply' => true,
    ]))->toBe(0);

    $account->refresh();
    expect((float) $account->current_balance)->toBe(110.0)
        ->and((float) $account->debt_balance)->toBe(0.0)
        ->and((float) $account->deposited_total)->toBe(150.0)
        ->and((float) $account->minimum_required)->toBe(0.0)
        ->and((float) $account->max_negative_balance)->toBe(999.0)
        ->and($account->is_active)->toBeTrue()
        ->and(DB::table('cleaning_deposit_transactions')->count())->toBe(2);

    expect($rootQueries)->toHaveCount(4)
        ->and($rootQueries[0])->toContain('cleaning_transaction_admission_locks')
        ->and($rootQueries[1])->toContain('select')->toContain('cleaning_worker_deposits')
        ->and($rootQueries[2])->toContain('cleaning_deposit_transactions')
        ->and($rootQueries[3])->toContain('update')->toContain('cleaning_worker_deposits');
});

it('re-reads changed account and ledger after a stale candidate snapshot simulation', function (): void {
    $workerId = reconciliationQaWorkerId();
    $account = reconciliationQaAccount($workerId);
    reconciliationQaEntry($workerId, 'deposit', 100);

    $connectionName = DB::getDefaultConnection();
    $connection = DB::connection($connectionName);
    $mutatedAfterCandidate = false;
    DB::listen(static function (QueryExecuted $event) use ($connectionName, $connection, $account, $workerId, &$mutatedAfterCandidate): void {
        $sql = mb_strtolower($event->sql);
        if ($mutatedAfterCandidate || $event->connectionName !== $connectionName
            || $connection->transactionLevel() !== 0
            || ! str_contains($sql, 'select')
            || ! str_contains($sql, 'cleaning_worker_deposits')) {
            return;
        }

        // Simulates a writer changing the row/ledger after advisory enumeration;
        // it is not a two-process MySQL race proof.
        $mutatedAfterCandidate = true;
        DB::table('cleaning_worker_deposits')->where('id', $account->id)->update([
            'current_balance' => 200,
            'deposited_total' => 200,
        ]);
        reconciliationQaEntry($workerId, 'deposit', 100);
    });

    expect(Artisan::call('cleaning:reconcile-worker-finances', [
        '--worker' => $workerId,
        '--apply' => true,
    ]))->toBe(0);

    expect($mutatedAfterCandidate)->toBeTrue()
        ->and((float) $account->fresh()->current_balance)->toBe(200.0)
        ->and((float) $account->fresh()->deposited_total)->toBe(200.0);
});

it('preserves the legacy fallback, valid worker scope, and repeated-apply behavior', function (): void {
    $workerId = reconciliationQaWorkerId();
    $otherWorkerId = reconciliationQaWorkerId();
    $account = reconciliationQaAccount($workerId);
    $otherAccount = reconciliationQaAccount($otherWorkerId);

    $arguments = ['--worker' => $workerId, '--apply' => true];
    expect(Artisan::call('cleaning:reconcile-worker-finances', $arguments))->toBe(0);

    $account->refresh();
    expect((float) $account->current_balance)->toBe(0.0)
        ->and((float) $account->debt_balance)->toBe(1.0)
        ->and((float) $account->deposited_total)->toBe(8.0)
        ->and((float) $account->minimum_required)->toBe(0.0)
        ->and((float) $otherAccount->fresh()->current_balance)->toBe(1.0)
        ->and((float) $otherAccount->fresh()->minimum_required)->toBe(60.0);

    expect(Artisan::call('cleaning:reconcile-worker-finances', $arguments))->toBe(0)
        ->and((float) $account->fresh()->current_balance)->toBe(0.0)
        ->and((float) $account->fresh()->debt_balance)->toBe(1.0)
        ->and((float) $otherAccount->fresh()->current_balance)->toBe(1.0);
});

it('fails apply closed without a sentinel while dry-run remains available and write-free', function (): void {
    $workerId = reconciliationQaWorkerId();
    $account = reconciliationQaAccount($workerId);
    reconciliationQaEntry($workerId, 'deposit', 100);
    DB::table('cleaning_transaction_admission_locks')->delete();

    $rootQueries = [];
    reconciliationRecordRootQueries($rootQueries);
    expect(reconciliationApply(['--worker' => $workerId, '--apply' => true]))->not->toBe(0);
    expect(collect($rootQueries)->contains(fn (string $sql): bool => str_contains($sql, 'cleaning_worker_deposits')))->toBeFalse()
        ->and(collect($rootQueries)->contains(fn (string $sql): bool => str_contains($sql, 'cleaning_deposit_transactions')))->toBeFalse();

    expect(Artisan::call('cleaning:reconcile-worker-finances', ['--worker' => $workerId]))->toBe(0)
        ->and((float) $account->fresh()->current_balance)->toBe(1.0)
        ->and((float) $account->fresh()->debt_balance)->toBe(2.0)
        ->and((float) $account->fresh()->minimum_required)->toBe(60.0)
        ->and(DB::table('cleaning_deposit_transactions')->count())->toBe(1);
});

it('rejects an inherited transaction without ending or writing through the caller root', function (): void {
    $workerId = reconciliationQaWorkerId();
    $account = reconciliationQaAccount($workerId);
    reconciliationQaEntry($workerId, 'deposit', 100);

    $connection = DB::connection();
    $connection->beginTransaction();
    $depth = $connection->transactionLevel();
    $rootQueries = [];
    reconciliationRecordRootQueries($rootQueries);

    expect(reconciliationApply(['--worker' => $workerId, '--apply' => true]))->not->toBe(0)
        ->and($connection->transactionLevel())->toBe($depth)
        ->and($connection->getPdo()->inTransaction())->toBeTrue();

    $commandQueries = $rootQueries;
    expect($commandQueries)->toHaveCount(1)
        ->and($commandQueries[0])->toContain('select')->toContain('cleaning_worker_deposits')
        ->and(collect($commandQueries)->contains(fn (string $sql): bool => str_contains($sql, 'cleaning_transaction_admission_locks')))->toBeFalse()
        ->and(collect($commandQueries)->contains(fn (string $sql): bool => str_contains($sql, 'cleaning_deposit_transactions')))->toBeFalse()
        ->and(collect($commandQueries)->contains(fn (string $sql): bool => str_contains($sql, 'update')))->toBeFalse()
        ->and((float) $account->fresh()->current_balance)->toBe(1.0)
        ->and((float) $account->fresh()->minimum_required)->toBe(60.0);
});

it('skips an account deleted after candidate enumeration without blocking other accounts', function (): void {
    $workerId = reconciliationQaWorkerId();
    $deletedWorkerId = reconciliationQaWorkerId();
    $deletedAccount = reconciliationQaAccount($workerId);
    $survivingAccount = reconciliationQaAccount($deletedWorkerId);
    reconciliationQaEntry($workerId, 'deposit', 100);
    reconciliationQaEntry($deletedWorkerId, 'deposit', 50);

    $connectionName = DB::getDefaultConnection();
    $connection = DB::connection($connectionName);
    $changedAfterCandidate = false;
    DB::listen(static function (QueryExecuted $event) use ($connectionName, $connection, $deletedAccount, &$changedAfterCandidate): void {
        $sql = mb_strtolower($event->sql);
        if ($changedAfterCandidate || $event->connectionName !== $connectionName
            || $connection->transactionLevel() !== 0
            || ! str_contains($sql, 'select')
            || ! str_contains($sql, 'cleaning_worker_deposits')) {
            return;
        }

        $changedAfterCandidate = true;
        DB::table('cleaning_worker_deposits')->where('id', $deletedAccount->id)->delete();
    });

    expect(Artisan::call('cleaning:reconcile-worker-finances', ['--apply' => true]))->toBe(0);

    expect($changedAfterCandidate)->toBeTrue()
        ->and(DB::table('cleaning_worker_deposits')->where('id', $deletedAccount->id)->exists())->toBeFalse()
        ->and((float) $survivingAccount->fresh()->current_balance)->toBe(50.0)
        ->and(DB::table('cleaning_deposit_transactions')->count())->toBe(2);
});

it('rechecks the effective worker filter on the locked account row', function (): void {
    $workerId = reconciliationQaWorkerId();
    $account = reconciliationQaAccount($workerId);
    reconciliationQaEntry($workerId, 'deposit', 100);

    $connectionName = DB::getDefaultConnection();
    $connection = DB::connection($connectionName);
    $changedAfterCandidate = false;
    $replacementWorkerId = reconciliationQaWorkerId();
    DB::listen(static function (QueryExecuted $event) use ($connectionName, $connection, $account, $replacementWorkerId, &$changedAfterCandidate): void {
        $sql = mb_strtolower($event->sql);
        if ($changedAfterCandidate || $event->connectionName !== $connectionName
            || $connection->transactionLevel() !== 0
            || ! str_contains($sql, 'select')
            || ! str_contains($sql, 'cleaning_worker_deposits')) {
            return;
        }

        $changedAfterCandidate = true;
        DB::table('cleaning_worker_deposits')->where('id', $account->id)->update([
            'worker_id' => $replacementWorkerId,
        ]);
    });

    expect(Artisan::call('cleaning:reconcile-worker-finances', [
        '--worker' => $workerId,
        '--apply' => true,
    ]))->toBe(0);

    expect($changedAfterCandidate)->toBeTrue()
        ->and((int) DB::table('cleaning_worker_deposits')->where('id', $account->id)->value('worker_id'))->toBe($replacementWorkerId)
        ->and((float) $account->fresh()->current_balance)->toBe(1.0)
        ->and((float) $account->fresh()->debt_balance)->toBe(2.0)
        ->and((float) $account->fresh()->minimum_required)->toBe(60.0)
        ->and(DB::table('cleaning_deposit_transactions')->count())->toBe(1);
});

it('rolls back only a failed account root and permits a later independent account run', function (): void {
    $firstWorkerId = reconciliationQaWorkerId();
    $secondWorkerId = reconciliationQaWorkerId();
    $firstAccount = reconciliationQaAccount($firstWorkerId);
    $secondAccount = reconciliationQaAccount($secondWorkerId);
    reconciliationQaEntry($firstWorkerId, 'deposit', 100);
    reconciliationQaEntry($secondWorkerId, 'deposit', 50);

    $connectionName = DB::getDefaultConnection();
    $connection = DB::connection($connectionName);
    $updates = 0;
    $injectFailure = true;
    DB::listen(static function (QueryExecuted $event) use ($connectionName, $connection, &$updates, &$injectFailure): void {
        $sql = mb_strtolower($event->sql);
        if (! $injectFailure || $event->connectionName !== $connectionName
            || $connection->transactionLevel() === 0
            || ! str_contains($sql, 'update')
            || ! str_contains($sql, 'cleaning_worker_deposits')) {
            return;
        }

        $updates++;
        if ($updates === 2) {
            $injectFailure = false;
            throw new RuntimeException('Injected after-account-update failure.');
        }
    });

    expect(reconciliationApply(['--apply' => true]))->not->toBe(0);

    expect($updates)->toBe(2)
        ->and((float) $firstAccount->fresh()->current_balance)->toBe(100.0)
        ->and((float) $firstAccount->fresh()->minimum_required)->toBe(0.0)
        ->and((float) $secondAccount->fresh()->current_balance)->toBe(1.0)
        ->and((float) $secondAccount->fresh()->debt_balance)->toBe(2.0)
        ->and((float) $secondAccount->fresh()->minimum_required)->toBe(60.0)
        ->and((float) $secondAccount->fresh()->max_negative_balance)->toBe(999.0)
        ->and($secondAccount->fresh()->is_active)->toBeTrue()
        ->and(DB::table('cleaning_deposit_transactions')->count())->toBe(2)
        ->and($connection->transactionLevel())->toBe(0)
        ->and($connection->getPdo()->inTransaction())->toBeFalse();

    expect(Artisan::call('cleaning:reconcile-worker-finances', [
        '--worker' => $secondWorkerId,
        '--apply' => true,
    ]))->toBe(0)
        ->and((float) $secondAccount->fresh()->current_balance)->toBe(50.0)
        ->and((float) $secondAccount->fresh()->debt_balance)->toBe(0.0)
        ->and((float) $secondAccount->fresh()->minimum_required)->toBe(0.0)
        ->and($connection->transactionLevel())->toBe(0);
});
