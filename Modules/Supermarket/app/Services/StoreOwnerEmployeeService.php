<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use App\Enums\UserModuleType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Supermarket\Models\SmStore;
use Modules\Supermarket\Models\SmStoreStaff;

final class StoreOwnerEmployeeService
{
    public function createOrLink(
        SmStore $store,
        string $name,
        ?string $email,
        ?string $phone,
        bool $isActive,
        array $permissionIds = []
    ): SmStoreStaff {
        return DB::transaction(function () use ($store, $name, $email, $phone, $isActive, $permissionIds): SmStoreStaff {
            $user = null;

            if ($email || $phone) {
                $user = User::query()
                    ->where(function ($query) use ($email, $phone): void {
                        if ($email) {
                            $query->where('email', $email);
                        }

                        if ($phone) {
                            $method = $email ? 'orWhere' : 'where';
                            $query->{$method}('phone', $phone);
                        }
                    })
                    ->first();
            }

            if (! $user) {
                $user = User::query()->create([
                    'name' => $name,
                    'email' => $email ?? Str::uuid().'@placeholder.local',
                    'phone' => $phone,
                    'password' => Hash::make(Str::random(24)),
                    'module_type' => UserModuleType::SupermarketSeller->value,
                ]);
            } else {
                $user->update([
                    'name' => $name,
                    'email' => $email ?? $user->email,
                    'phone' => $phone ?? $user->phone,
                    'module_type' => UserModuleType::SupermarketSeller->value,
                ]);
            }

            $user->permissions()->sync($permissionIds);

            /** @var SmStoreStaff $staff */
            $staff = SmStoreStaff::query()->updateOrCreate(
                [
                    'store_id' => $store->id,
                    'user_id' => $user->id,
                ],
                [
                    'is_active' => $isActive,
                ]
            );

            return $staff->load(['store', 'user.permissions']);
        });
    }
}
