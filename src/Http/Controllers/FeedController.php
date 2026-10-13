<?php

declare(strict_types=1);

namespace Vortech\Trace\Http\Controllers;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Throwable;
use Vortech\Trace\Exceptions\CursorExpiredException;
use Vortech\Trace\Exceptions\InvalidCursorException;
use Vortech\Trace\Exceptions\InvalidRequestException;
use Vortech\Trace\Exceptions\InvalidSourceException;
use Vortech\Trace\Source;
use Vortech\Trace\Source\InstanceId;
use Vortech\Trace\Support\TraceConfig;

abstract class FeedController
{
    /**
     * @param  Closure(Source, string): array<string, mixed>  $callback
     *
     * @throws Throwable
     */
    protected function respond(Request $request, Closure $callback): JsonResponse
    {
        $route = $request->route();

        throw_unless(
            condition: $route instanceof Route,
            exception: new InvalidSourceException('The request has no route.'),
        );

        $defaults = $route->defaults;
        $feed = $defaults['trace_feed'] ?? null;
        $class = $defaults['trace_source'] ?? null;

        throw_unless(
            condition: is_string($feed) && is_string($class),
            exception: new InvalidSourceException('The route does not carry a source.'),
        );

        $source = Source::resolve($class);
        $source->validate();

        try {
            $response = response()->json($callback($source, $feed));
        } catch (CursorExpiredException $exception) {
            $response = $this->error($exception->errorCode, $exception->getMessage(), 410);
        } catch (InvalidCursorException|InvalidRequestException $exception) {
            $response = $this->error($exception->errorCode, $exception->getMessage(), $exception->errorCode === 'cursor_expired' ? 410 : 422);
        }

        // also on errors: a client that sees another installation must not mistake it for a bad request
        return $response->header('X-Trace-Instance', InstanceId::get());
    }

    /**
     * @throws InvalidRequestException
     * @throws Throwable
     */
    protected function limit(Request $request): int
    {
        $limit = $request->query('limit');

        if (blank($limit)) {
            return TraceConfig::pageSize();
        }

        throw_unless(
            condition: is_string($limit) && ctype_digit($limit) && (int) $limit >= 1 && (int) $limit <= TraceConfig::maxPageSize(),
            exception: new InvalidRequestException('The limit must be an integer between 1 and '.TraceConfig::maxPageSize().'.', 'invalid_limit'),
        );

        return (int) $limit;
    }

    protected function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && filled($value) ? $value : null;
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => $code, 'message' => $message], $status);
    }
}
