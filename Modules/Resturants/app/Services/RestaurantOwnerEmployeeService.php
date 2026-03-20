<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use App\Enums\UserModuleType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
        string $password,
        bool $isActive,
        array $permissionIds = []
    ): RestaurantStaff {
        return DB::transaction(function () use ($restaurant, $name, $email, $phone, $password, $isActive, $permissionIds) {
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
                $user = User::create([
                    'name' => $name,
                    'email' => $email ?? Str::uuid().'@placeholder.local',
                    'phone' => $phone,
                    'password' => $password,
                    'module_type' => UserModuleType::RestaurantSeller->value,
                ]);
            } else {
                $user->update([
                    'name' => $name,
                    'email' => $email ?? $user->email,
                    'phone' => $phone ?? $user->phone,
                    'password' => $password,
                    'module_type' => UserModuleType::RestaurantSeller->value,
                ]);
            }

            $user->syncPermissions($permissionIds);

            /** @var RestaurantStaff $staff */
            $staff = RestaurantStaff::updateOrCreate(
                [
                    'restaurant_id' => $restaurant->id,
                    'user_id' => $user->id,
                ],
                [
                    'restaurant_role_id' => null,
                    'is_active' => $isActive,
                ]
            );

            return $staff->load(['restaurant', 'user.permissions', 'user.media']);
        });
    }
}
