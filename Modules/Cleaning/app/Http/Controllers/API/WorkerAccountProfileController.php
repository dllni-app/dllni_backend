<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Http\Resources\WorkerResource;
use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Cleaning\Http\Requests\WorkerAccountPasswordUpdateRequest;
use Modules\Cleaning\Http\Requests\WorkerAccountProfileUpdateRequest;
use Mrmarchone\LaravelAutoCrud\Helpers\MediaHelper;

final class WorkerAccountProfileController
{
    public function update(WorkerAccountProfileUpdateRequest $request): WorkerResource|JsonResponse
    {
        $worker = $this->worker();

        if (! $worker) {
            return response()->json(['message' => 'User must have an associated worker.'], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validated();
        $user = $worker->user;

        if ($user) {
            $updates = [];

            if (array_key_exists('name', $validated)) {
                $updates['name'] = $validated['name'];
            }
            if (array_key_exists('email', $validated)) {
                $updates['email'] = $validated['email'];
            }
            if (array_key_exists('phone', $validated)) {
                $updates['phone'] = $validated['phone'];
            }

            if ($updates !== []) {
                $user->update($updates);
            }
        }

        if ($request->hasFile('avatar')) {
            MediaHelper::updateMedia($request->file('avatar'), $worker, 'avatar');
        }

        $workerUpdates = [];
        if (array_key_exists('bio', $validated)) {
            $workerUpdates['bio'] = $validated['bio'];
        }
        if (array_key_exists('preferred_work_type', $validated)) {
            $workerUpdates['preferred_work_type'] = $validated['preferred_work_type'];
        }
        if (array_key_exists('isActive', $validated)) {
            $workerUpdates['is_active'] = (bool) $validated['isActive'];
        }
        if (array_key_exists('homeAddress', $validated)) {
            $workerUpdates['home_address'] = $validated['homeAddress'];
        }
        if (array_key_exists('homeLatitude', $validated)) {
            $workerUpdates['home_latitude'] = $validated['homeLatitude'];
        }
        if (array_key_exists('homeLongitude', $validated)) {
            $workerUpdates['home_longitude'] = $validated['homeLongitude'];
        }

        if ($workerUpdates !== []) {
            $worker->update($workerUpdates);
        }

        return WorkerResource::make($worker->fresh()->load(['user', 'zones', 'availability', 'media']));
    }

    public function updatePassword(WorkerAccountPasswordUpdateRequest $request): JsonResponse
    {
        $worker = $this->worker();

        if (! $worker) {
            return response()->json(['message' => 'User must have an associated worker.'], Response::HTTP_FORBIDDEN);
        }

        $user = auth()->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        $user->update([
            'password' => $request->validated()['newPassword'],
        ]);

        return response()->json([
            'message' => 'Password updated successfully.',
        ]);
    }

    private function worker(): ?Worker
    {
        return auth()->user()?->worker;
    }
}
