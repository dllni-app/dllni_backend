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
    private ?User $resolvedUser = null;

    private ?SmStore $resolvedStore = null;

    /** @throws AuthorizationException */
    public function owner(): User
    {
        return $this->authenticatedSeller();
    }

    /** @throws AuthorizationException */
    public function store(int $storeId): SmStore
    {
        $store = $this->ownedStore();

        if ((int) $store->id !== $storeId) {
            throw new AuthorizationException('You do not have access to this store.');
        }

        return $store;
    }

    /**
     * Resolve the authenticated seller's store. Owners are resolved directly;
     * active employees are resolved through their staff assignment.
     *
     * @throws AuthorizationException
     */
    public function ownedStore(): SmStore
    {
        if ($this->resolvedStore !== null) {
            return $this->resolvedStore;
        }

        $user = $this->authenticatedSeller();
        $store = $user->smStores()->orderBy('id')->first();

        if (! $store) {
            $store = SmStoreStaff::query()
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->with('store')
                ->orderBy('id')
                ->first()
                ?->store;
        }

        if (! $store) {
            throw new AuthorizationException('No store found for the authenticated supermarket seller.');
        }

        return $this->resolvedStore = $store;
    }

    /** @throws AuthorizationException */
    public function ensureOwnedStaff(SmStoreStaff $staff): void
    {
        $store = $this->ownedStore();

        if ((int) $staff->store_id !== (int) $store->id) {
            throw new AuthorizationException('You do not have access to this employee.');
        }
    }

    /** @throws AuthorizationException */
    private function authenticatedSeller(): User
    {
        if ($this->resolvedUser !== null) {
            return $this->resolvedUser;
        }

        /** @var User|null $user */
        $user = request()->user();

        if (! $user) {
            throw new AuthorizationException('Unauthenticated.');
        }

        if ($this->moduleTypeValue($user) !== UserModuleType::SupermarketSeller->value) {
            throw new AuthorizationException('This endpoint is for supermarket sellers only.');
        }

        return $this->resolvedUser = $user;
    }

    private function moduleTypeValue(User $user): ?string
    {
        $attributes = $user->getAttributes();
        $moduleType = $attributes['module_type'] ?? null;

        if ($moduleType === null && ! array_key_exists('module_type', $attributes)) {
            $moduleType = User::query()
                ->whereKey($user->getKey())
                ->value('module_type');
        }

        if ($moduleType instanceof UserModuleType) {
            return $moduleType->value;
        }

        return $moduleType !== null ? (string) $moduleType : null;
    }
}
