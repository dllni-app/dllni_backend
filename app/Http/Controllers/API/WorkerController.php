<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Data\WorkerData;
use App\Http\Requests\WorkerRequest;
use App\Http\Requests\WorkerRequests\WorkerFilterRequest;
use App\Http\Resources\WorkerResource;
use App\Models\Worker;
use App\Services\WorkerService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Throwable;

final class WorkerController
{
    public function __construct(
        private readonly WorkerService $workerService
    ) {}

    public function index(WorkerFilterRequest $request): AnonymousResourceCollection
    {
        $workers = Worker::getQuery()
            ->with(['user'])
            ->paginate($request->get('perPage', 20));

        return WorkerResource::collection($workers);
    }

    /** @throws Throwable */
    public function store(WorkerRequest $request): WorkerResource
    {
        $worker = $this->workerService->store(WorkerData::from($request->validated()));

        return WorkerResource::make(
            $worker->load(['user', 'zones', 'availability', 'trustLogs'])
        );
    }

    public function show(Worker $worker): WorkerResource
    {
        $worker->load([
            'user', 'zones', 'availability', 'trustLogs',
        ]);

        return WorkerResource::make($worker);
    }

    /** @throws Throwable */
    public function update(WorkerRequest $request, Worker $worker): WorkerResource
    {
        $updated = $this->workerService->update(WorkerData::from($request->validated()), $worker);

        return WorkerResource::make(
            $updated->load(['user', 'zones', 'availability', 'trustLogs'])
        );
    }

    public function destroy(Worker $worker): Response
    {
        $worker->delete();

        return response()->noContent();
    }
}
