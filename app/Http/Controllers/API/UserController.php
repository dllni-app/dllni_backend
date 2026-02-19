<?php

declare(strict_types=1);
namespace App\Http\Controllers\API;

use App\Models\User;
use App\Data\UserData;
use App\Services\UserService;
use App\Http\Requests\UserRequest;
use App\Http\Resources\UserResource;
use App\Traits\FilterQueries\UserFilterQuery;
use App\Http\Requests\UserFilterRequest;
use Illuminate\Http\Response;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Throwable;

class UserController
{
    public function __construct(protected UserService $userService){}

    public function index(UserFilterRequest $request): AnonymousResourceCollection
    {
        $users = User::getQuery()
            ->paginate($request->get('per_page', 20));

        return UserResource::collection($users);
    }

    /**
     * @throws Throwable
     */
    public function store(UserRequest $request): UserResource
    {
        $user = $this->userService->store(UserData::from($request->validated()));

        return UserResource::make($user->load('media'));
    }

    public function show(User $user): UserResource
    {
        return UserResource::make($user->load('media'));
    }

    /**
     * @throws Throwable
     */
    public function update(UserRequest $request, User $user): UserResource
    {
        $updatedUser = $this->userService->update(UserData::from($request->validated()), $user);

        return UserResource::make($updatedUser->load('media'));
    }

    public function destroy(User $user): Response
    {
        $user->delete();

        return response()->noContent();
    }
}

