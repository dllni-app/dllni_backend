<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

final class CleaningBookingCompleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'completionMessage' => ['nullable', 'string', 'max:1000'],
            'completion_message' => ['nullable', 'string', 'max:1000'],
            'cleaning_services' => ['nullable', 'array'],
            'cleaningServices' => ['nullable', 'array'],
            'propertiesRooms' => ['nullable', 'array'],
            'properties_rooms' => ['nullable', 'array'],
        ];
    }

    public function completionMessage(): ?string
    {
        $message = $this->validated('completionMessage') ?? $this->validated('completion_message');

        if (! is_string($message)) {
            return null;
        }

        $message = mb_trim($message);

        return $message !== '' ? $message : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function finishedCleaningServices(): array
    {
        return $this->normalizeItems(
            $this->validated('cleaning_services') ?? $this->validated('cleaningServices') ?? [],
            'service'
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function finishedPropertyRooms(): array
    {
        return $this->normalizeItems(
            $this->validated('propertiesRooms') ?? $this->validated('properties_rooms') ?? [],
            'room'
        );
    }

    /**
     * @param  mixed  $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeItems(mixed $items, string $type): array
    {
        if (! is_array($items)) {
            return [];
        }

        $normalized = [];

        foreach ($items as $item) {
            if (is_string($item)) {
                $label = mb_trim($item);
                if ($label !== '') {
                    $normalized[] = ['name' => $label, 'label' => $label];
                }
                continue;
            }

            if (! is_array($item)) {
                continue;
            }

            $id = Arr::get($item, 'id');
            $label = Arr::get($item, 'label')
                ?? Arr::get($item, 'name')
                ?? Arr::get($item, 'displayLabel')
                ?? Arr::get($item, 'display_label')
                ?? Arr::get($item, 'roomTypeLabel')
                ?? Arr::get($item, 'room_type_label')
                ?? Arr::get($item, 'roomType')
                ?? Arr::get($item, 'room_type')
                ?? Arr::get($item, 'roomKey')
                ?? Arr::get($item, 'room_key');

            $payload = [];

            if (is_numeric($id)) {
                $payload['id'] = (int) $id;
            }

            foreach ([
                'name',
                'label',
                'roomKey',
                'room_key',
                'roomType',
                'room_type',
                'roomTypeLabel',
                'room_type_label',
                'displayLabel',
                'display_label',
            ] as $key) {
                $value = Arr::get($item, $key);
                if (is_string($value) && mb_trim($value) !== '') {
                    $payload[$key] = mb_trim($value);
                }
            }

            if (! isset($payload['label']) && is_string($label) && mb_trim($label) !== '') {
                $payload['label'] = mb_trim($label);
            }

            if ($type === 'service' && ! isset($payload['name']) && isset($payload['label'])) {
                $payload['name'] = $payload['label'];
            }

            if ($payload !== []) {
                $normalized[] = $payload;
            }
        }

        return array_values($normalized);
    }
}
