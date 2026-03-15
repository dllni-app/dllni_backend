<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use App\Enums\UserModuleType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\Supermarket\Models\SmStore;
use Modules\Supermarket\Models\SmStoreStaff;

final class StoreOwnerContextService
{
    private ?User $resolvedOwner = null;

    /** @throws AuthorizationException */
    public function owner(): User
    {
        $owner = $this->authenticatedOwner();

        if (! $owner) {
            throw new AuthorizationException('Unauthenticated.');
        }

        return $owner;
    }

    /** @throws AuthorizationException */
    public function store(int $storeId): SmStore
    {
        $store = SmStore::query()->find($storeId);

        if (! $store) {
            throw new AuthorizationException('You do not have access to this store.');
        }

        $owner = $this->authenticatedOwner();

        if ($owner && (int) $store->owner_user_id !== (int) $owner->id) {
            throw new AuthorizationException('You do not have access to this store.');
        }

        return $store;
    }

    /** @throws AuthorizationException */
    public function ensureOwnedStaff(SmStoreStaff $staff): void
    {
        $owner = $this->authenticatedOwner();

        if (! $owner) {
            return;
        }

        $isOwned = SmStore::query()
            ->where('id', $staff->store_id)
            ->where('owner_user_id', $owner->id)
            ->exists();

        if (! $isOwned) {
            throw new AuthorizationException('You do not have access to this employee.');
        }
    }

    /** @throws AuthorizationException */
    private function authenticatedOwner(): ?User
    {
        if ($this->resolvedOwner !== null) {
            return $this->resolvedOwner;
        }

        /** @var User|null $user */
        $user = request()->user();

        if (! $user) {
            return null;
        }

        if ($user->module_type !== UserModuleType::SupermarketSeller) {
            throw new AuthorizationException('This endpoint is for supermarket sellers only.');
        }

        return $this->resolvedOwner = $user;
    }
}
