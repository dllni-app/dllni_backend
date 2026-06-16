<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

trait ResolvesFcmToken
{
    /**
     * Resolve an FCM token from known body aliases, then fallback headers.
     */
    protected function resolveFcmToken(): ?string
    {
        foreach ($this->fcmInputAliases() as $key) {
            if (! $this->exists($key)) {
                continue;
            }

            $token = $this->normalizeFcmTokenValue($this->input($key));
            if ($token !== null) {
                return $token;
            }
        }

        foreach ($this->fcmHeaderAliases() as $key) {
            $token = $this->normalizeFcmTokenValue($this->header($key));
            if ($token !== null) {
                return $token;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function fcmInputAliases(): array
    {
        return [
            'fcmToken',
            'fcm_token',
            'deviceToken',
            'device_token',
            'pushToken',
            'push_token',
            'token',
        ];
    }

    /**
     * @return list<string>
     */
    private function fcmHeaderAliases(): array
    {
        return [
            'fcm-token',
            'x-fcm-token',
            'fcm_token',
        ];
    }

    private function normalizeFcmTokenValue(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
