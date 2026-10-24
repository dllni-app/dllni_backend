<?php

declare(strict_types=1);

use App\Models\CleaningDepositTransaction;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

const P3_RUN08_HTTP_CONNECTION = 'p3_run08_http_isolated';

beforeEach(function (): void {
    config(['cleaning_transaction_admission.admin_deposit_pilot_enabled' => true]);
    $this->run08OriginalConnection = DB::getDefaultConnection();
    $this->run08OriginalConfig = config('database.connections.'.P3_RUN08_HTTP_CONNECTION);

    config(['database.connections.'.P3_RUN08_HTTP_CONNECTION => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);
    DB::purge(P3_RUN08_HTTP_CONNECTION);
    DB::setDefaultConnection(P3_RUN08_HTTP_CONNECTION);

    expect(Artisan::call('migrate', [
        '--database' => P3_RUN08_HTTP_CONNECTION,
        '--force' => true,
        '--no-interaction' => true,
    ]))->toBe(0);

    if (! Schema::hasTable('cleaning_transaction_admission_locks')) {
        (require base_path('Modules/Cleaning/database/migrations/2026_10_18_000000_create_cleaning_transaction_admission_locks.php'))->up();
    }

    expect(DB::connection()->transactionLevel())->toBe(0);
});

afterEach(function (): void {
    DB::setDefaultConnection($this->run08OriginalConnection);
    DB::disconnect(P3_RUN08_HTTP_CONNECTION);
    DB::purge(P3_RUN08_HTTP_CONNECTION);
    config(['database.connections.'.P3_RUN08_HTTP_CONNECTION => $this->run08OriginalConfig]);
});

function p3Run08Worker(): Worker
{
    return Worker::factory()->create([
        'trust_score' => 90,
        'is_active' => true,
        'is_suspended' => false,
        'security_deposit_status' => 'active',
    ]);
}

function p3Run08Admin(): User
{
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');

    return $admin;
}

function p3Run08DepositUrl(Worker $worker): string
{
    return "/api/v1/admin/cleaning/deposits/{$worker->id}/deposit";
}

it('admits an authenticated HTTP deposit with sentinel as first transaction SQL and nested financial writes', function (): void {
    $admin = p3Run08Admin();
    $worker = p3Run08Worker();
    Sanctum::actingAs($admin);

    $trace = [];
    $capture = false;
    DB::listen(static function ($event) use (&$trace, &$capture): void {
        if ($capture && $event->connectionName === P3_RUN08_HTTP_CONNECTION) {
            $trace[] = [
                'sql' => mb_strtolower($event->sql),
                'depth' => $event->connection->transactionLevel(),
            ];
        }
    });

    $capture = true;
    $response = $this->postJson(p3Run08DepositUrl($worker), [
        'amount' => 125.50,
        'reference' => 'p3_run08_http_deposit',
    ]);
    $capture = false;

    $response->assertCreated()->assertJsonPath('transaction.reference', 'p3_run08_http_deposit');

    $rootQueries = collect($trace)->filter(static fn (array $query): bool => $query['depth'] >= 1)->values();
    expect($rootQueries)->not->toBeEmpty()
        ->and($rootQueries->first()['sql'])->toContain('cleaning_transaction_admission_locks')
        ->and($rootQueries->first()['depth'])->toBe(1)
        ->and(collect($trace)->contains(static fn (array $query): bool => $query['depth'] === 0 && str_contains($query['sql'], 'workers')))->toBeTrue()
        ->and(collect($trace)->contains(static fn (array $query): bool => $query['depth'] === 2 && str_contains($query['sql'], 'cleaning_worker_deposits')))->toBeTrue()
        ->and(DB::connection()->transactionLevel())->toBe(0);

    expect((float) CleaningWorkerDeposit::query()->where('worker_id', $worker->id)->firstOrFail()->current_balance)->toBe(125.50)
        ->and(CleaningDepositTransaction::query()->where('worker_id', $worker->id)->where('reference', 'p3_run08_http_deposit')->count())->toBe(1);
});

it('fails closed before a financial mutation when the sentinel is missing', function (): void {
    $admin = p3Run08Admin();
    $worker = p3Run08Worker();
    Sanctum::actingAs($admin);
    DB::table('cleaning_transaction_admission_locks')->where('id', 1)->delete();

    $this->postJson(p3Run08DepositUrl($worker), ['amount' => 100, 'reference' => 'must_not_record'])
        ->assertBadRequest()
        ->assertJsonPath('message', 'Cleaning admission sentinel is missing.');

    expect(DB::connection()->transactionLevel())->toBe(0)
        ->and(CleaningWorkerDeposit::query()->where('worker_id', $worker->id)->count())->toBe(0)
        ->and(CleaningDepositTransaction::query()->where('worker_id', $worker->id)->count())->toBe(0);
});

it('keeps unauthenticated and invalid deposits write-free', function (): void {
    $worker = p3Run08Worker();
    $this->postJson(p3Run08DepositUrl($worker), ['amount' => 20])->assertUnauthorized();

    Sanctum::actingAs(p3Run08Admin());
    $this->postJson(p3Run08DepositUrl($worker), ['amount' => -3])->assertUnprocessable();

    expect(CleaningWorkerDeposit::query()->where('worker_id', $worker->id)->count())->toBe(0)
        ->and(CleaningDepositTransaction::query()->where('worker_id', $worker->id)->count())->toBe(0);
});

it('leaves the legacy manual-deposit behavior unchanged when the unaccepted pilot is disabled', function (): void {
    config(['cleaning_transaction_admission.admin_deposit_pilot_enabled' => false]);
    $worker = p3Run08Worker();
    Sanctum::actingAs(p3Run08Admin());

    // The pilot-only sentinel is deliberately absent; existing deployments
    // are not migrated or silently switched by this experimental code path.
    DB::table('cleaning_transaction_admission_locks')->where('id', 1)->delete();

    $this->postJson(p3Run08DepositUrl($worker), [
        'amount' => 80,
        'reference' => 'p3_pilot_disabled_legacy',
    ])->assertCreated()->assertJsonPath('transaction.reference', 'p3_pilot_disabled_legacy');

    expect((float) CleaningWorkerDeposit::query()->where('worker_id', $worker->id)->firstOrFail()->current_balance)->toBe(80.0)
        ->and(CleaningDepositTransaction::query()->where('worker_id', $worker->id)->count())->toBe(1);
});

it('settles an existing worker debt under the same admitted root before crediting the remainder', function (): void {
    $worker = p3Run08Worker();
    Sanctum::actingAs(p3Run08Admin());
    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 0,
        'debt_balance' => 60,
        'deposited_total' => 0,
        'withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 100,
        'is_active' => true,
    ]);

    $this->postJson(p3Run08DepositUrl($worker), [
        'amount' => 100,
        'reference' => 'p3_pilot_settlement',
    ])->assertCreated()->assertJsonPath('transaction.reference', 'p3_pilot_settlement:deposit-remainder');

    $account = CleaningWorkerDeposit::query()->where('worker_id', $worker->id)->firstOrFail();
    expect((float) $account->current_balance)->toBe(40.0)
        ->and((float) $account->debt_balance)->toBe(0.0)
        ->and(CleaningDepositTransaction::query()->where('worker_id', $worker->id)->where('reference', 'p3_pilot_settlement:debt-settlement')->count())->toBe(1)
        ->and(CleaningDepositTransaction::query()->where('worker_id', $worker->id)->where('reference', 'p3_pilot_settlement:deposit-remainder')->count())->toBe(1)
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

it('does not credit a worker deleted after HTTP route binding but before admission', function (): void {
    $worker = p3Run08Worker();
    Sanctum::actingAs(p3Run08Admin());
    $armed = false;
    $deletedDuringBinding = false;
    DB::listen(static function ($event) use ($worker, &$armed, &$deletedDuringBinding): void {
        if (
            ! $armed || $deletedDuringBinding
            || $event->connectionName !== P3_RUN08_HTTP_CONNECTION
            || $event->connection->transactionLevel() !== 0
            || ! str_contains(mb_strtolower($event->sql), 'from "workers"')
        ) {
            return;
        }

        // Simulates an interleaving after route binding has already fetched
        // the Worker but before the controller enters its admitted root.
        $deletedDuringBinding = true;
        Worker::query()->whereKey($worker->id)->delete();
    });

    $armed = true;
    $response = $this->postJson(p3Run08DepositUrl($worker), [
        'amount' => 90,
        'reference' => 'p3_deleted_after_binding',
    ]);
    $armed = false;

    expect($deletedDuringBinding)->toBeTrue();
    $response->assertBadRequest();
    expect(CleaningWorkerDeposit::query()->where('worker_id', $worker->id)->count())->toBe(0)
        ->and(CleaningDepositTransaction::query()->where('worker_id', $worker->id)->count())->toBe(0)
        ->and(DB::connection()->transactionLevel())->toBe(0);
});
