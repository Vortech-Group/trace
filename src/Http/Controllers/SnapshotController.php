<?php

declare(strict_types=1);

namespace Vortech\Trace\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;
use Vortech\Trace\Source;
use Vortech\Trace\Source\SnapshotReader;

final class SnapshotController extends FeedController
{
    /**
     * @throws Throwable
     */
    public function __invoke(Request $request, SnapshotReader $reader): JsonResponse
    {
        return $this->respond($request, fn (Source $source, string $feed): array => $reader->read(
            source: $source,
            feed: $feed,
            token: $this->stringQuery($request, 'cursor'),
            limit: $this->limit($request),
        ));
    }
}
