<?php

declare(strict_types=1);

namespace Vortech\Trace\Source;

use Throwable;
use Vortech\Trace\Enums\CursorType;
use Vortech\Trace\Exceptions\InvalidConfigurationException;
use Vortech\Trace\Exceptions\InvalidCursorException;

/**
 * An opaque, HMAC-signed position. A client can store it and hand it back, not edit it.
 * Snapshot cursors expire, change cursors live as long as the log retains their position.
 */
final readonly class Cursor
{
    public function __construct(
        public CursorType $type,
        public string $feed,
        public string $instanceId,
        public int $version,
        public ?string $lastKey = null,
        public ?int $expiresAt = null,
    ) {}

    /**
     * @throws InvalidConfigurationException
     * @throws Throwable
     */
    public function encode(): string
    {
        $body = $this->base64(json_encode([
            't' => $this->type->value,
            'f' => $this->feed,
            'i' => $this->instanceId,
            'v' => $this->version,
            'k' => $this->lastKey,
            'e' => $this->expiresAt,
        ], JSON_THROW_ON_ERROR));

        return $body.'.'.$this->base64(self::sign($body));
    }

    /**
     * @throws InvalidCursorException
     * @throws InvalidConfigurationException
     * @throws Throwable
     */
    public static function decode(string $token, CursorType $type, string $feed, string $instanceId): self
    {
        [$body, $signature] = array_pad(explode('.', $token, 2), 2, '');

        throw_if(
            condition: blank($body) || blank($signature) || ! hash_equals(self::sign($body), self::unbase64($signature)),
            exception: new InvalidCursorException('The cursor is malformed or was tampered with.'),
        );

        $data = json_decode(self::unbase64($body), true);

        throw_unless(
            condition: is_array($data)
                && ($data['t'] ?? null) === $type->value
                && ($data['f'] ?? null) === $feed
                && ($data['i'] ?? null) === $instanceId
                && is_int($data['v'] ?? null)
                && $data['v'] >= 0,
            exception: new InvalidCursorException("The cursor does not belong to this {$type->value} feed."),
        );

        $lastKey = $data['k'] ?? null;
        $expiresAt = $data['e'] ?? null;

        throw_if(
            condition: ($lastKey !== null && ! is_string($lastKey)) || ($expiresAt !== null && ! is_int($expiresAt)),
            exception: new InvalidCursorException('The cursor is malformed.'),
        );

        throw_if(
            condition: is_int($expiresAt) && $expiresAt < now()->getTimestamp(),
            exception: new InvalidCursorException('The snapshot cursor expired, start a new snapshot.', 'cursor_expired'),
        );

        return new self($type, $feed, $instanceId, $data['v'], $lastKey, $expiresAt);
    }

    /**
     * Reads a stored cursor for display only. The signature is not checked: never trust the result.
     *
     * @return array{type: string, version: int, lastKey: string|null}|null
     */
    public static function peek(?string $token): ?array
    {
        $data = blank($token) ? null : json_decode(self::unbase64(explode('.', $token, 2)[0]), true);

        if (! is_array($data) || ! is_string($data['t'] ?? null) || ! is_int($data['v'] ?? null)) {
            return null;
        }

        return ['type' => $data['t'], 'version' => $data['v'], 'lastKey' => is_string($data['k'] ?? null) ? $data['k'] : null];
    }

    /**
     * @throws InvalidConfigurationException
     * @throws Throwable
     */
    private static function sign(string $body): string
    {
        $key = config('app.key');

        throw_if(
            condition: ! is_string($key) || blank($key),
            exception: new InvalidConfigurationException('Cursors are signed with app.key, which is not set.'),
        );

        return hash_hmac('sha256', $body, 'trace|'.$key, true);
    }

    private function base64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function unbase64(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
