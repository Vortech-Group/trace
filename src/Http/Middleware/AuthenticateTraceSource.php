<?php

declare(strict_types=1);

namespace Vortech\Trace\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vortech\Trace\Support\TraceConfig;

/** Fail closed: no token configured means no data, never public access. */
final class AuthenticateTraceSource
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = TraceConfig::sourceToken();

        if (blank($expected)) {
            return $this->deny('source_not_configured', 'The Trace source token is not configured.', 503);
        }

        if (app()->environment('production') && ! $request->isSecure()) {
            return $this->deny('https_required', 'The Trace feed is only served over HTTPS.', 403);
        }

        $provided = $request->bearerToken();

        if (! is_string($provided) || ! hash_equals($expected, $provided)) {
            return $this->deny('unauthenticated', 'The bearer token is missing or invalid.', 401)
                ->header('WWW-Authenticate', 'Bearer');
        }

        return $next($request);
    }

    private function deny(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => $code, 'message' => $message], $status);
    }
}
