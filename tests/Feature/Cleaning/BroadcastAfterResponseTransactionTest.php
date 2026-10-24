<?php

declare(strict_types=1);

use App\Support\Broadcast\BroadcastAfterResponse;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

function broadcastAfterResponseTestEvent(): ShouldBroadcast
{
    return new class implements ShouldBroadcast
    {
        public function broadcastOn(): array
        {
            return [];
        }
    };
}

/** @param list<ShouldBroadcast> $dispatched */
function fakeBroadcastAfterResponseBoundary(array &$dispatched, ?Throwable $failure = null): void
{
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->zeroOrMoreTimes()->andReturnUsing(
        static function (mixed $event) use (&$dispatched, $failure): mixed {
            if ($event instanceof ShouldBroadcast) {
                if ($failure !== null) {
                    throw $failure;
                }

                $dispatched[] = $event;
            }

            return $event;
        }
    );

    app()->instance('events', $dispatcher);
}

beforeEach(function (): void {
    $ownedConnection = 'broadcast_after_response_test';
    app()->instance('broadcast-after-response.original-database-default', config('database.default'));
    $originalOwnedConnectionConfig = config("database.connections.{$ownedConnection}");
    app()->instance('broadcast-after-response.original-owned-connection-config', [
        'exists' => $originalOwnedConnectionConfig !== null,
        'value' => $originalOwnedConnectionConfig,
    ]);
    app()->instance('broadcast-after-response.original-transactions-manager', app('db.transactions'));

    Config::set("database.connections.{$ownedConnection}", [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    Config::set('database.default', $ownedConnection);
    DB::purge($ownedConnection);
    app()->instance('db.transactions', new DatabaseTransactionsManager);
});

afterEach(function (): void {
    $ownedConnection = 'broadcast_after_response_test';
    $originalDefault = app('broadcast-after-response.original-database-default');
    Config::set('database.default', $originalDefault);

    $connection = DB::connection($ownedConnection);
    while ($connection->transactionLevel() > 0) {
        $connection->rollBack();
    }

    DB::disconnect($ownedConnection);
    DB::purge($ownedConnection);
    app()->instance('db.transactions', app('broadcast-after-response.original-transactions-manager'));

    $originalConnectionConfig = app('broadcast-after-response.original-owned-connection-config');
    if (! $originalConnectionConfig['exists']) {
        Config::offsetUnset("database.connections.{$ownedConnection}");
    } else {
        Config::set("database.connections.{$ownedConnection}", $originalConnectionConfig['value']);
    }
});

it('registers immediately with a cold transaction manager and unopened PDO', function (): void {
    $ownedConnection = 'broadcast_after_response_test';
    expect(DB::getConnections())->not->toHaveKey($ownedConnection);

    $dispatched = [];
    fakeBroadcastAfterResponseBoundary($dispatched);
    $event = broadcastAfterResponseTestEvent();

    BroadcastAfterResponse::send($event);
    $connection = DB::connection($ownedConnection);
    $pdoProperty = new ReflectionProperty(Connection::class, 'pdo');
    expect($pdoProperty->getValue($connection))->toBeInstanceOf(Closure::class)
        ->and($dispatched)->toBeEmpty();

    app()->terminate();

    expect($dispatched)->toBe([$event]);
});

it('waits for the outermost commit after a nested commit', function (): void {
    $connection = DB::connection();
    $connection->beginTransaction();
    $connection->beginTransaction();
    $dispatched = [];
    fakeBroadcastAfterResponseBoundary($dispatched);
    $event = broadcastAfterResponseTestEvent();

    BroadcastAfterResponse::send($event);
    $connection->commit();
    app()->terminate();
    expect($dispatched)->toBeEmpty();

    $connection->commit();
    app()->terminate();
    expect($dispatched)->toBe([$event]);
});

it('discards a pending broadcast when the root transaction rolls back', function (): void {
    $connection = DB::connection();
    $connection->beginTransaction();
    $dispatched = [];
    fakeBroadcastAfterResponseBoundary($dispatched);

    BroadcastAfterResponse::send(broadcastAfterResponseTestEvent());
    $connection->rollBack();
    app()->terminate();

    expect($dispatched)->toBeEmpty();
});

it('discards only the nested registration when a savepoint rolls back', function (): void {
    $connection = DB::connection();
    $connection->beginTransaction();
    $rootEvent = broadcastAfterResponseTestEvent();
    $nestedEvent = broadcastAfterResponseTestEvent();
    $dispatched = [];
    fakeBroadcastAfterResponseBoundary($dispatched);

    BroadcastAfterResponse::send($rootEvent);
    $connection->beginTransaction();
    BroadcastAfterResponse::send($nestedEvent);
    $connection->rollBack();
    $connection->commit();
    app()->terminate();

    expect($dispatched)->toBe([$rootEvent]);
});

it('does not broadcast after a service returns and its transaction later rolls back', function (): void {
    $connection = DB::connection();
    $connection->beginTransaction();
    $dispatched = [];
    fakeBroadcastAfterResponseBoundary($dispatched);
    $event = broadcastAfterResponseTestEvent();
    $service = static fn (): null => BroadcastAfterResponse::send($event);

    $service();
    $connection->rollBack();
    app()->terminate();

    expect($dispatched)->toBeEmpty();
});

it('keeps the commit callback tied to its connection when the default changes', function (): void {
    $connection = DB::connection();
    $connection->beginTransaction();
    $dispatched = [];
    fakeBroadcastAfterResponseBoundary($dispatched);
    $event = broadcastAfterResponseTestEvent();

    BroadcastAfterResponse::send($event);
    Config::set('database.default', 'sqlite');
    $otherConnection = DB::connection();
    $otherConnection->beginTransaction();
    $otherConnection->commit();

    app()->terminate();
    expect($dispatched)->toBeEmpty();

    $connection->commit();
    app()->terminate();

    expect($dispatched)->toBe([$event]);
});

it('discards a failed attempt callback before the retried transaction commits', function (): void {
    $connection = DB::connection();
    $dispatched = [];
    fakeBroadcastAfterResponseBoundary($dispatched);
    $event = broadcastAfterResponseTestEvent();

    $connection->beginTransaction();
    BroadcastAfterResponse::send($event);
    $connection->rollBack();

    $connection->beginTransaction();
    BroadcastAfterResponse::send($event);
    $connection->commit();
    app()->terminate();

    expect($dispatched)->toBe([$event]);
});

it('delivers only once when the application terminates repeatedly', function (): void {
    $connection = DB::connection();
    $dispatched = [];
    fakeBroadcastAfterResponseBoundary($dispatched);
    $event = broadcastAfterResponseTestEvent();

    BroadcastAfterResponse::send($event);
    app()->terminate();
    app()->terminate();

    expect($dispatched)->toBe([$event]);
});

it('swallows and logs a broadcast transport failure', function (): void {
    $connection = DB::connection();
    $failure = new RuntimeException('fake broadcast transport failure');
    $dispatched = [];
    fakeBroadcastAfterResponseBoundary($dispatched, $failure);
    Log::shouldReceive('warning')->once()->with(
        'Broadcast delivery failed after response.',
        Mockery::on(static fn (array $context): bool => $context['message'] === $failure->getMessage())
    );

    BroadcastAfterResponse::send(broadcastAfterResponseTestEvent());
    app()->terminate();

    expect($dispatched)->toBeEmpty();
});
