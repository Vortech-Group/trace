<?php

declare(strict_types=1);

namespace Vortech\Trace\Remote;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;
use Vortech\Trace\Data\FeedPage;
use Vortech\Trace\Data\SyncDefinition;
use Vortech\Trace\Exceptions\CursorExpiredException;
use Vortech\Trace\Exceptions\InstanceMismatchException;
use Vortech\Trace\Exceptions\InvalidConfigurationException;
use Vortech\Trace\Exceptions\ProtocolException;
use Vortech\Trace\Exceptions\RemoteAuthenticationException;
use Vortech\Trace\Exceptions\RemoteUnavailableException;
use Vortech\Trace\Support\Payload;
use Vortech\Trace\Support\TraceConfig;

/** Talks the Trace protocol and turns every failure into a typed exception. */
final readonly class RemoteHttpClient
{
    /**
     * @throws Throwable
     */
    public function snapshot(SyncDefinition $definition, ?string $cursor, int $limit, ?string $expectedInstance = null): FeedPage
    {
        return $this->get($definition, 'snapshot', ['cursor' => $cursor, 'limit' => $limit], $expectedInstance);
    }

    /**
     * @throws Throwable
     */
    public function changes(SyncDefinition $definition, ?string $cursor, ?string $since, int $limit, ?string $expectedInstance = null): FeedPage
    {
        return $this->get($definition, 'changes', ['cursor' => $cursor, 'since' => $since, 'limit' => $limit], $expectedInstance);
    }

    /**
     * @param  array<string, string|int|null>  $query
     *
     * @throws Throwable
     */
    private function get(SyncDefinition $definition, string $endpoint, array $query, ?string $expectedInstance): FeedPage
    {
        $request = $this->request($definition->connection);

        try {
            $response = $request->get("{$definition->feed}/{$endpoint}", array_filter($query, filled(...)));
        } catch (ConnectionException $exception) {
            throw new RemoteUnavailableException('The source could not be reached: '.$exception->getMessage(), previous: $exception);
        }

        $this->guard($response, $expectedInstance);

        return FeedPage::fromApi(Payload::map($response->json(), 'the response body'), $definition->feed);
    }

    /**
     * @throws InvalidConfigurationException
     * @throws Throwable
     */
    private function request(string $connection): PendingRequest
    {
        $settings = TraceConfig::remote($connection);

        throw_if(
            condition: app()->environment('production') && ! str_starts_with($settings['url'], 'https://'),
            exception: new InvalidConfigurationException("The remote url of [{$connection}] must use HTTPS in production."),
        );

        return Http::baseUrl($settings['url'])
            ->withToken($settings['token'])
            ->acceptJson()
            ->timeout(TraceConfig::timeout())
            ->retry(
                times: TraceConfig::retries(),
                sleepMilliseconds: 250,
                when: fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && ($exception->response->status() === 429 || $exception->response->serverError())),
                throw: false,
            );
    }

    /**
     * @throws Throwable
     */
    private function guard(Response $response, ?string $expectedInstance): void
    {
        if ($response->successful()) {
            return;
        }

        $instance = $response->header('X-Trace-Instance');

        throw_if(
            condition: filled($expectedInstance) && filled($instance) && $instance !== $expectedInstance,
            exception: new InstanceMismatchException("The source answers as instance [{$instance}] but this feed was synced from [{$expectedInstance}]. Trace never re-pairs on its own."),
        );

        $body = $response->json();
        $message = is_array($body) && is_string($body['message'] ?? null) ? $body['message'] : "HTTP {$response->status()}";

        throw match (true) {
            $response->status() === 401 => new RemoteAuthenticationException("The source rejected the token: {$message}", 'remote_unauthenticated'),
            $response->status() === 403 => new RemoteAuthenticationException("The source denied access to the feed: {$message}", 'remote_forbidden'),
            $response->status() === 404 => new ProtocolException("The source has no such feed: {$message}", 'feed_not_found'),
            $response->status() === 410 => new CursorExpiredException($message),
            $response->status() === 429 || $response->serverError() => new RemoteUnavailableException("The source is unavailable: {$message}"),
            default => new ProtocolException("The source refused the request: {$message}", 'invalid_request'),
        };
    }
}
