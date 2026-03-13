<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use App\Enums\UserModuleType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Models\RestaurantStaff;

final class RestaurantOwnerEmployeeService
{
    public function createOrLink(
        Restaurant $restaurant,
        string $name,
        ?string $email,
        ?string $phone,
        bool $isActive,
        array $permissionIds = []
    ): RestaurantStaff {
        return DB::transaction(function () use ($restaurant, $name, $email, $phone, $isActive, $permissionIds) {
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
                    'module_type' => UserModuleType::RestaurantSeller->value,
                ]);
            } else {
                $user->update([
                    'name' => $name,
                    'email' => $email ?? $user->email,
                    'phone' => $phone ?? $user->phone,
                    'module_type' => UserModuleType::RestaurantSeller->value,
                ]);
            }

            $user->syncPermissions($permissionIds);

            /** @var RestaurantStaff $staff */
            $staff = RestaurantStaff::query()->updateOrCreate(
                [
                    'restaurant_id' => $restaurant->id,
                    'user_id' => $user->id,
                ],
                [
                    'restaurant_role_id' => null,
                    'is_active' => $isActive,
                ]
            );

            return $staff->load(['restaurant', 'user.permissions']);
        });
    }
}
