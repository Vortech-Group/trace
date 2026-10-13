<?php

declare(strict_types=1);

namespace Vortech\Trace\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;
use Vortech\Trace\Exceptions\InvalidRequestException;
use Vortech\Trace\Source;
use Vortech\Trace\Source\ChangeFeedReader;

final class ChangesController extends FeedController
{
    /**
     * @throws Throwable
     */
    public function __invoke(Request $request, ChangeFeedReader $reader): JsonResponse
    {
        return $this->respond($request, fn (Source $source, string $feed): array => $reader->read(
            source: $source,
            feed: $feed,
            token: $this->stringQuery($request, 'cursor'),
            since: $this->since($request),
            limit: $this->limit($request),
        ));
    }

    /**
     * @throws InvalidRequestException
     * @throws Throwable
     */
    private function since(Request $request): ?int
    {
        $since = $this->stringQuery($request, 'since');

        throw_if(
            condition: filled($since) && ! ctype_digit($since),
            exception: new InvalidRequestException('The since version must be a non-negative integer.', 'invalid_since'),
        );

        return blank($since) ? null : (int) $since;
    }
}
