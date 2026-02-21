<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Support\Str;
use Mrmarchone\LaravelAutoCrud\Helpers\PermissionNameResolver;

trait AuthorizesByPermissionGroup
{
    protected function authorizeAction($user, string $action): bool
    {
        $group = $this->resolvePermissionGroup();

        return $user->can(PermissionNameResolver::resolve($group, $action));
    }

    protected function resolvePermissionGroup(): string
    {
        $className = class_basename(static::class);
        $modelName = str_replace('Policy', '', $className);

        if (Str::startsWith($modelName, 'Sm')) {
            $modelName = Str::substr($modelName, 2);
        }

        return Str::plural(Str::snake($modelName));
    }
}
