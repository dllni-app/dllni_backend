<?php

declare(strict_types=1);

use App\Models\CleaningDepositTransaction;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

/**
 * The framework's RefreshDatabase wrapper owns the current connection root.
 * The admitted API must reject it without attempting a fallback deposit.
 * The Run07 pre-adoption successful HTTP trace remains in its QA evidence;
 * the bounded Run08 path uses an independent depth-zero test connection.
 */
it('rejects an inherited HTTP database root without recording a deposit', function (): void {
    config(['cleaning_transaction_admission.admin_deposit_pilot_enabled' => true]);
    expect(DB::connection()->getDriverName())->toBe('sqlite');

    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $worker = Worker::factory()->create([
        'trust_score' => 90,
        'is_active' => true,
        'is_suspended' => false,
        'security_deposit_status' => 'active',
    ]);
    Sanctum::actingAs($admin);

    $trace = [];
    $connectionName = DB::getDefaultConnection();
    DB::listen(static function ($event) use (&$trace, $connectionName): void {
        if ($event->connectionName !== $connectionName) {
            return;
        }
        $trace[] = [
            'sql' => $event->sql,
            'depth' => $event->connection->transactionLevel(),
        ];
    });

    $baselineDepth = DB::connection()->transactionLevel();
    $response = $this->postJson(
        "/api/v1/admin/cleaning/deposits/{$worker->id}/deposit",
        ['amount' => 125.50, 'reference' => 'p3_run07_deposit_http'],
    );

    $response->assertBadRequest()
        ->assertJsonPath('message', 'Cleaning admission requires an unowned root transaction.');

    $firstWorker = array_key_first(array_filter(
        $trace,
        static fn (array $query): bool => preg_match('/\b(?:from|join) ["`]?workers["`]?\b/i', $query['sql']) === 1,
    ));
    expect($trace)->not->toBeEmpty()
        ->and($firstWorker)->not->toBeNull()
        ->and($trace[$firstWorker]['depth'])->toBe($baselineDepth)
        ->and(collect($trace)->contains(static fn (array $query): bool => $query['depth'] > $baselineDepth))->toBeFalse()
        ->and(DB::connection()->transactionLevel())->toBe($baselineDepth);

    expect(CleaningWorkerDeposit::query()->where('worker_id', $worker->id)->count())->toBe(0)
        ->and(CleaningDepositTransaction::query()->where('worker_id', $worker->id)->where('reference', 'p3_run07_deposit_http')->count())->toBe(0);

    fwrite(STDOUT, '\nP3_RUN08_ADMIN_DEPOSIT_INHERITED_ROOT_TRACE='.json_encode([
        'first_worker_sql' => $trace[$firstWorker],
        'baseline_depth' => $baselineDepth,
        'observed_depths' => collect($trace)->pluck('depth')->unique()->sort()->values()->all(),
        'query_count' => count($trace),
    ], JSON_UNESCAPED_SLASHES).'\n');
});
