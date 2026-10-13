<?php

declare(strict_types=1);

use Vortech\Trace\Enums\CursorType;
use Vortech\Trace\Exceptions\InvalidCursorException;
use Vortech\Trace\Source\Cursor;

it('round-trips a cursor', function (): void {
    $token = new Cursor(CursorType::Snapshot, 'clinics', 'inst-1', 42, '17', now()->getTimestamp() + 60)->encode();
    $cursor = Cursor::decode($token, CursorType::Snapshot, 'clinics', 'inst-1');

    expect($cursor->version)->toBe(42)
        ->and($cursor->lastKey)->toBe('17')
        ->and($cursor->feed)->toBe('clinics');
});

it('rejects a tampered cursor', function (): void {
    $token = new Cursor(CursorType::Changes, 'clinics', 'inst-1', 5)->encode();
    [$body, $signature] = explode('.', $token);
    $forged = rtrim(strtr(base64_encode((string) json_encode(['t' => 'changes', 'f' => 'clinics', 'i' => 'inst-1', 'v' => 0, 'k' => null, 'e' => null])), '+/', '-_'), '=');

    expect(fn () => Cursor::decode($forged.'.'.$signature, CursorType::Changes, 'clinics', 'inst-1'))->toThrow(InvalidCursorException::class)
        ->and(fn () => Cursor::decode($body.'.x', CursorType::Changes, 'clinics', 'inst-1'))->toThrow(InvalidCursorException::class)
        ->and(fn () => Cursor::decode('garbage', CursorType::Changes, 'clinics', 'inst-1'))->toThrow(InvalidCursorException::class);
});

it('rejects a cursor of another type, feed or instance', function (): void {
    $token = new Cursor(CursorType::Changes, 'clinics', 'inst-1', 5)->encode();

    expect(fn () => Cursor::decode($token, CursorType::Snapshot, 'clinics', 'inst-1'))->toThrow(InvalidCursorException::class)
        ->and(fn () => Cursor::decode($token, CursorType::Changes, 'doctors', 'inst-1'))->toThrow(InvalidCursorException::class)
        ->and(fn () => Cursor::decode($token, CursorType::Changes, 'clinics', 'inst-2'))->toThrow(InvalidCursorException::class);
});

it('lets a snapshot cursor expire', function (): void {
    $token = new Cursor(CursorType::Snapshot, 'clinics', 'inst-1', 5, '3', now()->getTimestamp() - 1)->encode();

    expect(fn () => Cursor::decode($token, CursorType::Snapshot, 'clinics', 'inst-1'))
        ->toThrow(InvalidCursorException::class, 'expired');
});

it('does not decode with another application key', function (): void {
    $token = new Cursor(CursorType::Changes, 'clinics', 'inst-1', 5)->encode();
    config(['app.key' => 'base64:'.base64_encode(str_repeat('z', 32))]);

    expect(fn () => Cursor::decode($token, CursorType::Changes, 'clinics', 'inst-1'))->toThrow(InvalidCursorException::class);
});
