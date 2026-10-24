<?php

declare(strict_types=1);

use App\Support\Broadcast\BroadcastAfterResponse;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Cleaning\Events\CompletionDecisionMade;

it('delivers a committed broadcast at most once across repeated termination', function (): void {
    Event::fake([CompletionDecisionMade::class]);
    DB::commit();

    BroadcastAfterResponse::send(new CompletionDecisionMade(
        41,
        12,
        'extension_accepted',
        null,
        now()->toIso8601String(),
    ));

    app()->terminate();
    Event::assertDispatchedTimes(CompletionDecisionMade::class, 1);

    app()->terminate();
    Event::assertDispatchedTimes(CompletionDecisionMade::class, 1);
});

it('waits for the outer transaction commit before registering response delivery', function (): void {
    Event::fake([CompletionDecisionMade::class]);
    trackOuterDatabaseTransaction();

    BroadcastAfterResponse::send(new CompletionDecisionMade(
        42,
        13,
        'extension_rejected',
        null,
        now()->toIso8601String(),
    ));

    app()->terminate();
    Event::assertNothingDispatched();

    DB::commit();
    app()->terminate();

    Event::assertDispatchedTimes(CompletionDecisionMade::class, 1);
});

it('does not deliver a broadcast when the outer transaction rolls back', function (): void {
    Event::fake([CompletionDecisionMade::class]);
    trackOuterDatabaseTransaction();

    BroadcastAfterResponse::send(new CompletionDecisionMade(
        43,
        14,
        'extension_rejected',
        null,
        now()->toIso8601String(),
    ));

    DB::rollBack();
    app()->terminate();

    Event::assertNothingDispatched();
});

function trackOuterDatabaseTransaction(): void
{
    $connection = DB::connection();
    $transactionsManager = new DatabaseTransactionsManager;
    $transactionsManager->begin($connection->getName(), $connection->transactionLevel());
    $connection->setTransactionManager($transactionsManager);
}
