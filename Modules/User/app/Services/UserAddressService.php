<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\User\Models\UserAddress;

final class UserAddressService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function store(User $user, array $data): UserAddress
    {
        return DB::transaction(function () use ($user, $data): UserAddress {
            $attributes = $this->locationAttributesFromPayload($data);
            $hasExisting = $user->addresses()->exists();

            $wantsDefault = (bool) ($data['isDefault'] ?? false);
            if (! $hasExisting) {
                $wantsDefault = true;
            }

            $address = $user->addresses()->create(
                array_merge($attributes, ['is_default' => false])
            );

            if ($wantsDefault) {
                $this->markAsOnlyDefault($user, $address);
            }

            return $address->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(UserAddress $address, array $data): UserAddress
    {
        return DB::transaction(function () use ($address, $data): UserAddress {
            $user = $address->user;
            $wasDefault = $address->is_default;

            $attributes = $this->locationAttributesFromPayload($data);
            $address->fill($attributes);
            $address->save();

            if (! array_key_exists('isDefault', $data)) {
                return $address->fresh();
            }

            $wantsDefault = (bool) $data['isDefault'];

            if ($wantsDefault) {
                $this->markAsOnlyDefault($user, $address);

                return $address->fresh();
            }

            $address->update(['is_default' => false]);

            if ($wasDefault) {
                $this->promoteFirstAsDefault($user, excludingId: null);
            }

            return $address->fresh();
        });
    }

    public function delete(UserAddress $address): void
    {
        DB::transaction(function () use ($address): void {
            $user = $address->user;
            $wasDefault = $address->is_default;
            $address->delete();

            if ($wasDefault) {
                $this->promoteFirstAsDefault($user, excludingId: null);
            }
        });
    }

    public function setDefault(UserAddress $address): UserAddress
    {
        return DB::transaction(function () use ($address): UserAddress {
            $this->markAsOnlyDefault($address->user, $address);

            return $address->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function locationAttributesFromPayload(array $data): array
    {
        return [
            'label' => $data['label'],
            'city' => $data['city'] ?? null,
            'neighborhood' => $data['neighborhood'] ?? null,
            'street' => $data['street'] ?? null,
            'building' => $data['building'] ?? null,
            'floor' => $data['floor'] ?? null,
            'directions' => $data['directions'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
        ];
    }

    private function markAsOnlyDefault(User $user, UserAddress $keep): void
    {
        $user->addresses()->where('id', '!=', $keep->id)->update(['is_default' => false]);
        $keep->update(['is_default' => true]);
    }

    private function promoteFirstAsDefault(User $user, ?int $excludingId): void
    {
        $query = $user->addresses()->orderBy('id');

        if ($excludingId !== null) {
            $query->where('id', '!=', $excludingId);
        }

        $first = $query->first();

        if ($first === null) {
            return;
        }

        $first->update(['is_default' => true]);
    }
}
