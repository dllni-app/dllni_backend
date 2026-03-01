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
use Mrmarchone\LaravelAutoCrud\Helpers\MediaHelper;
use Throwable;

final class WorkerController
{
    public function __construct(
        private readonly WorkerService $workerService
    ) {}

    public function index(WorkerFilterRequest $request): AnonymousResourceCollection
    {
        $workers = Worker::getQuery()
            ->with(['user', 'media'])
            ->paginate($request->get('perPage', 20));

        return WorkerResource::collection($workers);
    }

    /** @throws Throwable */
    public function store(WorkerRequest $request): WorkerResource
    {
        $validated = $request->validated();
        unset($validated['avatar']);
        $worker = $this->workerService->store(WorkerData::from($validated));

        if ($request->hasFile('avatar')) {
            MediaHelper::updateMedia($request->file('avatar'), $worker, 'avatar');
        }

        return WorkerResource::make(
            $worker->load(['user', 'zones', 'availability', 'trustLogs', 'media'])
        );
    }

    public function show(Worker $worker): WorkerResource
    {
        $worker->load([
            'user', 'zones', 'availability', 'trustLogs', 'media',
        ]);

        return WorkerResource::make($worker);
    }

    /** @throws Throwable */
    public function update(WorkerRequest $request, Worker $worker): WorkerResource
    {
        $validated = $request->validated();
        unset($validated['avatar']);
        $updated = $this->workerService->update(WorkerData::from($validated), $worker);

        if ($request->hasFile('avatar')) {
            MediaHelper::updateMedia($request->file('avatar'), $updated, 'avatar');
        }

        return WorkerResource::make(
            $updated->load(['user', 'zones', 'availability', 'trustLogs', 'media'])
        );
    }

    public function destroy(Worker $worker): Response
    {
        $worker->delete();

        return response()->noContent();
    }
}
