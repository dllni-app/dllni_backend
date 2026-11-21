<?php

declare(strict_types=1);

namespace Modules\Cleaning\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Cleaning\Services\WorkerComplianceVisitMatcher;

final class EvaluateWorkerComplianceSamples implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 240;

    public function __construct(public readonly int $workerId)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'worker-compliance-evaluate-'.$this->workerId;
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(WorkerComplianceVisitMatcher $matcher): void
    {
        $matcher->evaluateWorker($this->workerId);
    }
}
