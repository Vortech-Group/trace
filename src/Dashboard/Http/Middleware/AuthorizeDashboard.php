<?php

declare(strict_types=1);

namespace Vortech\Trace\Dashboard\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class AuthorizeDashboard
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $ability = 'viewTrace'): Response
    {
        abort_unless(Gate::forUser($request->user())->allows($ability), 404);

        return $next($request);
    }
}
