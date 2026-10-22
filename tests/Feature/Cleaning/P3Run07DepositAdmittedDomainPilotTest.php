<?php

declare(strict_types=1);

use App\Models\CleaningDepositTransaction;
use App\Models\CleaningWorkerDeposit;
use App\Models\Worker;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Cleaning\Services\CleaningTransactionAdmissionService as Admission;
use Modules\Cleaning\Services\DepositService;

const P3_RUN07_DEPOSIT_PILOT_CONNECTION = 'p3_run07_deposit_pilot';

beforeEach(function (): void {
    $this->pilotPreviousDefault = DB::getDefaultConnection();
    $this->pilotPreviousConfig = config('database.connections.'.P3_RUN07_DEPOSIT_PILOT_CONNECTION);
    config(['database.connections.'.P3_RUN07_DEPOSIT_PILOT_CONNECTION => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);
    DB::purge(P3_RUN07_DEPOSIT_PILOT_CONNECTION);
    DB::setDefaultConnection(P3_RUN07_DEPOSIT_PILOT_CONNECTION);

    // All migrations and mutations are confined to a NEW in-memory SQLite
    // connection; the framework-owned RefreshDatabase transaction is untouched.
    $result = Artisan::call('migrate', [
        '--database' => P3_RUN07_DEPOSIT_PILOT_CONNECTION,
        '--force' => true,
        '--no-interaction' => true,
    ]);
    expect($result)->toBe(0);
    if (! Schema::hasTable('cleaning_transaction_admission_locks')) {
        $migration = require base_path('Modules/Cleaning/database/migrations/2026_10_18_000000_create_cleaning_transaction_admission_locks.php');
        $migration->up();
    }
});

afterEach(function (): void {
    DB::setDefaultConnection($this->pilotPreviousDefault);
    DB::disconnect(P3_RUN07_DEPOSIT_PILOT_CONNECTION);
    DB::purge(P3_RUN07_DEPOSIT_PILOT_CONNECTION);
    config(['database.connections.'.P3_RUN07_DEPOSIT_PILOT_CONNECTION => $this->pilotPreviousConfig]);
});

it('pilots a real financial domain service under the admitted root without activating any production caller', function (): void {
    $worker = Worker::factory()->create(['trust_score' => 100, 'security_deposit_status' => 'active']);

    $queries = [];
    $capturing = false;
    DB::listen(static function ($event) use (&$queries, &$capturing): void {
        if ($capturing && $event->connectionName === P3_RUN07_DEPOSIT_PILOT_CONNECTION) {
            $queries[] = ['sql' => $event->sql, 'depth' => $event->connection->transactionLevel()];
        }
    });

    $capturing = true;
    $first = Admission::run(static fn () => app(DepositService::class)->recordDeposit(
        $worker, 125.50, 'p3_pilot_deposit_one',
    ));
    $capturing = false;

    expect((string) $first->reference)->toBe('p3_pilot_deposit_one')
        ->and($queries)->not->toBeEmpty()
        ->and($queries[0]['sql'])->toContain('cleaning_transaction_admission_locks')
        ->and($queries[0]['depth'])->toBe(1)
        ->and(collect($queries)->contains(static fn (array $query): bool => $query['depth'] === 2))->toBeTrue()
        ->and(DB::connection()->transactionLevel())->toBe(0);

    $account = CleaningWorkerDeposit::query()->where('worker_id', $worker->id)->firstOrFail();
    expect((float) $account->current_balance)->toBe(125.50)
        ->and(CleaningDepositTransaction::query()->where('worker_id', $worker->id)->count())->toBe(1);

    expect(fn () => Admission::run(static function () use ($worker): void {
        app(DepositService::class)->recordDeposit($worker, 75, 'p3_pilot_rolled_back');
        throw new RuntimeException('force outer transaction rollback');
    }))->toThrow(RuntimeException::class, 'force outer transaction rollback');

    expect((float) CleaningWorkerDeposit::query()->where('worker_id', $worker->id)->firstOrFail()->current_balance)->toBe(125.50)
        ->and(CleaningDepositTransaction::query()->where('worker_id', $worker->id)->count())->toBe(1)
        ->and(DB::connection()->transactionLevel())->toBe(0);

    Admission::run(static fn () => app(DepositService::class)->recordDeposit(
        $worker, 25, 'p3_pilot_deposit_two',
    ));

    expect((float) CleaningWorkerDeposit::query()->where('worker_id', $worker->id)->firstOrFail()->current_balance)->toBe(150.50)
        ->and(CleaningDepositTransaction::query()->where('worker_id', $worker->id)->count())->toBe(2);
});

it('refuses to run a financial command when the admission sentinel is missing', function (): void {
    $worker = Worker::factory()->create(['trust_score' => 100]);
    DB::table('cleaning_transaction_admission_locks')->where('id', 1)->delete();
    $callbackReached = false;

    expect(fn () => Admission::run(static function () use ($worker, &$callbackReached): void {
        $callbackReached = true;
        app(DepositService::class)->recordDeposit($worker, 100, 'must_not_record');
    }))->toThrow(RuntimeException::class, 'sentinel is missing');

    expect($callbackReached)->toBeFalse()
        ->and(CleaningDepositTransaction::query()->where('worker_id', $worker->id)->count())->toBe(0)
        ->and(CleaningWorkerDeposit::query()->where('worker_id', $worker->id)->count())->toBe(0)
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

it('rejects a financial command inside a foreign transaction without taking ownership', function (): void {
    $worker = Worker::factory()->create(['trust_score' => 100]);
    DB::beginTransaction();

    try {
        expect(fn () => Admission::run(static fn () => app(DepositService::class)->recordDeposit(
            $worker, 100, 'must_not_record_under_foreign_root',
        )))->toThrow(LogicException::class, 'unowned root transaction');

        expect(DB::connection()->transactionLevel())->toBe(1)
            ->and(CleaningDepositTransaction::query()->where('worker_id', $worker->id)->count())->toBe(0);
    } finally {
        DB::rollBack();
    }

    expect(DB::connection()->transactionLevel())->toBe(0);
});
