<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Cleaning\Services\SupervisedTeamService;
use Symfony\Component\HttpFoundation\Response;

final class EnsureSupervisor
{
    public function __construct(private readonly SupervisedTeamService $teams) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->teams->assertSupervisor($request->user());

        return $next($request);
    }
}
