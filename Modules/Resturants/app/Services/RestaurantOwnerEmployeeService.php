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
        int $roleId,
        string $name,
        ?string $email,
        ?string $phone,
        bool $isActive
    ): RestaurantStaff {
        return DB::transaction(function () use ($restaurant, $roleId, $name, $email, $phone, $isActive) {
            $user = User::query()
                ->when($email, fn ($query) => $query->orWhere('email', $email))
                ->when($phone, fn ($query) => $query->orWhere('phone', $phone))
                ->first();

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
                ]);
            }

            /** @var RestaurantStaff $staff */
            $staff = RestaurantStaff::query()->updateOrCreate(
                [
                    'restaurant_id' => $restaurant->id,
                    'user_id' => $user->id,
                ],
                [
                    'restaurant_role_id' => $roleId,
                    'is_active' => $isActive,
                ]
            );

            return $staff->load(['restaurant', 'user', 'role.permissions']);
        });
    }
}
