<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Modules\Resturants\Models\Restaurant;

final class RestaurantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $restaurantRoute = $this->route('restaurant');
        $restaurantId = null;

        if ($restaurantRoute instanceof Restaurant) {
            $restaurantId = $restaurantRoute->id;
        } elseif (is_numeric($restaurantRoute)) {
            $restaurantId = (int) $restaurantRoute;
        } else {
            $owner = auth()->user();
            if ($owner) {
                /** @var Restaurant|null $ownedRestaurant */
                $ownedRestaurant = $owner->restaurants()->first();
                $restaurantId = $ownedRestaurant?->id;
            }
        }

        $isCreate = $this->isMethod('post');
        $requiredOnCreate = $isCreate ? 'required' : 'sometimes';

        return [
            'userId' => [$requiredOnCreate, 'integer', 'exists:users,id'],
            'name' => [$requiredOnCreate, 'string', 'max:255'],
            'slug' => [$requiredOnCreate, 'string', 'max:255', 'unique:restaurants,slug,'.$restaurantId],
            'description' => 'nullable|string|max:200',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'district' => 'nullable|string|max:100',
            'locationDetails' => 'nullable|string|max:500',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'phone' => 'nullable|string|max:50',
            'whatsappNumber' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'instagramUsername' => 'nullable|string|max:100',
            'facebookPageName' => 'nullable|string|max:100',
            'averageRating' => 'nullable|numeric|min:0|max:5',
            'totalReviews' => 'nullable|integer|min:0',
            'estimatedPreparationTime' => 'nullable|integer|min:0',
            'minimumOrderAmount' => 'nullable|numeric|min:0',
            'priceRange' => 'nullable|string|in:low,medium,high,premium',
            'reputationScore' => 'nullable|integer|min:0|max:100',
            'warningCount' => 'nullable|integer|min:0',
            'visibilityScore' => 'nullable|integer|min:0',
            'manualVisibilityOverride' => 'nullable|boolean',
            'isActive' => 'nullable|boolean',
            'isFeatured' => 'nullable|boolean',
            'isTemporarilyClosed' => 'nullable|boolean',
            'suspensionUntil' => 'nullable|date',
            'primaryImage' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            'image' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            'bannerImage' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            'banner' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
        ];
    }

    protected function prepareForValidation(): void
    {
        $ownerRestaurant = null;
        $owner = auth()->user();

        if ($owner) {
            /** @var Restaurant|null $ownerRestaurant */
            $ownerRestaurant = $owner->restaurants()->first();
        }

        $name = $this->input('name', $ownerRestaurant?->name);
        $slug = $this->input('slug');

        if (! is_string($slug) || trim($slug) === '') {
            $slug = is_string($name) && trim($name) !== '' ? Str::slug($name) : null;
        }

        if (! is_string($slug) || trim($slug) === '') {
            $slug = $ownerRestaurant?->slug ?: 'restaurant-'.($ownerRestaurant?->id ?? Str::lower(Str::random(8)));
        }

        $this->merge([
            'userId' => $this->input('userId', $this->input('user_id', $ownerRestaurant?->user_id ?? auth()->id())),
            'name' => $name,
            'slug' => $slug,
            'whatsappNumber' => $this->input('whatsappNumber', $this->input('whatsapp')),
            'facebookPageName' => $this->input('facebookPageName', $this->input('face')),
            'instagramUsername' => $this->input('instagramUsername', $this->input('instagram')),
            'latitude' => $this->input('latitude', $this->input('lat')),
            'longitude' => $this->input('longitude', $this->input('long')),
            'locationDetails' => $this->input('locationDetails', $this->input('location_details')),
        ]);

        if ($this->hasFile('image') && ! $this->hasFile('primaryImage')) {
            $this->files->set('primaryImage', $this->file('image'));
        }

        if ($this->hasFile('banner') && ! $this->hasFile('bannerImage')) {
            $this->files->set('bannerImage', $this->file('banner'));
        }
    }
}
