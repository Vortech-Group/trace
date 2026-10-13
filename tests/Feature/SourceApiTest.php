<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Testing\TestResponse;
use Vortech\Trace\Models\TraceChange;
use Vortech\Trace\Models\TraceStream;
use Vortech\Trace\Tests\Support\Product;
use Vortech\Trace\Tests\TestCase;

function feed(string $uri): TestResponse
{
    return test()->getJson($uri, ['Authorization' => 'Bearer '.TestCase::TOKEN]);
}

it('registers class based routes that survive route caching', function (): void {
    $routes = collect(app(Router::class)->getRoutes()->getRoutes())->filter(fn (Route $route): bool => str_starts_with($route->uri(), 'api/products/'));

    expect($routes)->toHaveCount(2)
        ->and($routes->map(fn (Route $route): array => $route->methods())->flatten()->unique()->values()->all())->toBe(['GET', 'HEAD']);

    foreach ($routes as $route) {
        expect($route->getAction('uses'))->toBeString()
            ->and($route->gatherMiddleware())->toContain('trace.auth', 'throttle:trace');

        $route->prepareForSerialization();
    }
});

it('stays closed without a token and rejects a wrong one', function (): void {
    $this->getJson('/api/products/snapshot')->assertUnauthorized();
    $this->getJson('/api/products/snapshot', ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
    $this->getJson('/api/products/snapshot', ['Authorization' => 'Basic '.TestCase::TOKEN])->assertUnauthorized();

    config(['trace.source.token' => null]);
    $this->getJson('/api/products/snapshot', ['Authorization' => 'Bearer '])->assertStatus(503)->assertJsonPath('error', 'source_not_configured');
    $this->getJson('/api/products/snapshot', ['Authorization' => 'Bearer anything'])->assertStatus(503);

    config(['trace.source.token' => '']);
    $this->getJson('/api/products/snapshot')->assertStatus(503);
});

it('requires https in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    try {
        feed('/api/products/snapshot')->assertForbidden()->assertJsonPath('error', 'https_required');
        $this->getJson('https://localhost/api/products/snapshot', ['Authorization' => 'Bearer '.TestCase::TOKEN])->assertOk();
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
});

it('only answers GET', function (): void {
    $this->postJson('/api/products/snapshot', [], ['Authorization' => 'Bearer '.TestCase::TOKEN])->assertStatus(405);
});

it('answers 404 for an unknown feed', function (): void {
    feed('/api/doctors/snapshot')->assertNotFound();
});

it('serves a snapshot with keys, whitelisted attributes and protocol metadata', function (): void {
    makeProduct(['code' => 'PR-1', 'name' => 'Central', 'barcode' => '+3612345678', 'internal_note' => 'secret']);

    $response = feed('/api/products/snapshot?limit=10')->assertOk();

    $response->assertJsonPath('data.0.key', '1')
        ->assertJsonPath('data.0.attributes.code', 'PR-1')
        ->assertJsonPath('data.0.attributes.barcode', '+3612345678')
        ->assertJsonPath('data.0.attributes.is_active', true)
        ->assertJsonMissingPath('data.0.attributes.internal_note')
        ->assertJsonMissingPath('data.0.attributes.id')
        ->assertJsonPath('meta.protocol', 1)
        ->assertJsonPath('meta.feed', 'products')
        ->assertJsonPath('meta.watermark', '1')
        ->assertJsonPath('meta.has_more', false)
        ->assertJsonPath('meta.next_cursor', null);

    expect($response->json('meta.instance_id'))->toBeString()->not->toBeEmpty()
        ->and($response->json('meta.schema_hash'))->toStartWith('sha256:');
});

it('pages a snapshot by key with a stable watermark and without offsets', function (): void {
    foreach (range(1, 5) as $_) {
        makeProduct();
    }

    $first = feed('/api/products/snapshot?limit=2')->assertOk();
    expect($first->json('data.*.key'))->toBe(['1', '2'])->and($first->json('meta.has_more'))->toBeTrue();

    // rows appear and vanish between pages; keyset paging neither repeats nor skips what stays
    Product::query()->findOrFail(1)->delete();
    makeProduct();

    $second = feed('/api/products/snapshot?limit=2&cursor='.urlencode($first->json('meta.next_cursor')))->assertOk();
    expect($second->json('data.*.key'))->toBe(['3', '4'])
        ->and($second->json('meta.watermark'))->toBe($first->json('meta.watermark'));

    $third = feed('/api/products/snapshot?limit=2&cursor='.urlencode($second->json('meta.next_cursor')))->assertOk();
    expect($third->json('data.*.key'))->toBe(['5', '6'])->and($third->json('meta.has_more'))->toBeFalse();
});

it('does not list soft deleted records in the snapshot', function (): void {
    makeProduct();
    inTx(fn () => makeProduct()->delete());

    expect(feed('/api/products/snapshot')->json('data.*.key'))->toBe(['1']);
});

it('rejects bad limits and cursors with 422', function (string $uri): void {
    feed($uri)->assertStatus(422);
})->with([
    '/api/products/snapshot?limit=0',
    '/api/products/snapshot?limit=-1',
    '/api/products/snapshot?limit=abc',
    '/api/products/snapshot?limit=1001',
    '/api/products/snapshot?cursor=garbage',
    '/api/products/changes',
    '/api/products/changes?cursor=garbage',
    '/api/products/changes?since=abc',
    '/api/products/changes?since=-3',
]);

it('answers a cursor of another feed or tampered body with 422', function (): void {
    makeProduct();
    $cursor = feed('/api/products/changes?since=0')->json('meta.next_cursor');

    feed('/api/products/changes?cursor='.urlencode($cursor.'x'))->assertStatus(422);
    feed('/api/products/changes?cursor='.urlencode($cursor).'&since=0')->assertStatus(422);
});

it('serves upserts with current attributes and tombstones for deleted keys', function (): void {
    $kept = makeProduct(['name' => 'Kept']);
    $gone = makeProduct(['name' => 'Gone']);
    inTx(fn () => $kept->update(['name' => 'Kept (updated)']));
    inTx(fn () => $gone->delete());

    $data = feed('/api/products/changes?since=0')->assertOk()->json('data');

    expect($data)->toHaveCount(4)
        ->and($data[0])->toMatchArray(['version' => '1', 'key' => '1', 'operation' => 'upsert'])
        ->and($data[0]['attributes']['name'])->toBe('Kept (updated)')
        ->and($data[3])->toBe(['version' => '4', 'key' => '2', 'operation' => 'delete'])
        ->and($data[2]['version'])->toBe('3');
});

it('reports a restore as an upsert', function (): void {
    $product = makeProduct();
    inTx(fn () => $product->delete());
    inTx(fn () => $product->restore());

    $operations = collect(feed('/api/products/changes?since=0')->json('data'))->pluck('operation')->all();

    expect($operations)->toBe(['upsert', 'upsert', 'upsert'])
        ->and(TraceChange::query()->orderBy('version')->get()->pluck('operation.value')->all())->toBe(['changed', 'deleted', 'changed']);
});

it('logs a force delete exactly once, as a tombstone', function (): void {
    $product = makeProduct();
    inTx(fn () => $product->forceDelete());

    $changes = TraceChange::query()->orderBy('version')->get();
    $data = feed('/api/products/changes?since=0')->json('data');

    expect($changes->pluck('operation.value')->all())->toBe(['changed', 'deleted'])
        ->and($data[1])->toBe(['version' => '2', 'key' => '1', 'operation' => 'delete']);
});

it('pages the changes and continues from next_cursor', function (): void {
    foreach (range(1, 5) as $_) {
        makeProduct();
    }

    $first = feed('/api/products/changes?since=0&limit=2')->assertOk();
    expect($first->json('data.*.version'))->toBe(['1', '2'])->and($first->json('meta.has_more'))->toBeTrue();

    $second = feed('/api/products/changes?limit=2&cursor='.urlencode($first->json('meta.next_cursor')))->assertOk();
    expect($second->json('data.*.version'))->toBe(['3', '4']);

    $third = feed('/api/products/changes?limit=2&cursor='.urlencode($second->json('meta.next_cursor')))->assertOk();
    expect($third->json('data.*.version'))->toBe(['5'])->and($third->json('meta.has_more'))->toBeFalse();

    $empty = feed('/api/products/changes?limit=2&cursor='.urlencode($third->json('meta.next_cursor')))->assertOk();
    expect($empty->json('data'))->toBe([])->and($empty->json('meta.next_cursor'))->toBeString();
});

it('answers 410 cursor_expired when the position left the retention window', function (): void {
    makeProduct();
    makeProduct();
    $cursor = feed('/api/products/changes?since=0&limit=1')->json('meta.next_cursor');
    $stream = TraceStream::query()->firstOrFail();
    $stream->forceFill(['pruned_version' => 2])->save();

    feed('/api/products/changes?cursor='.urlencode($cursor))->assertStatus(410)->assertJsonPath('error', 'cursor_expired');
    feed('/api/products/changes?since=0')->assertStatus(410);
    feed('/api/products/changes?since=2')->assertOk();
});

it('answers 410 when the cursor is ahead of the source', function (): void {
    makeProduct();

    feed('/api/products/changes?since=99')->assertStatus(410);
});

it('answers 410 for an expired snapshot cursor', function (): void {
    foreach (range(1, 3) as $_) {
        makeProduct();
    }

    $cursor = feed('/api/products/snapshot?limit=1')->json('meta.next_cursor');
    $this->travel(2)->days();

    feed('/api/products/snapshot?limit=1&cursor='.urlencode($cursor))->assertStatus(410);
});

it('serves an empty feed with a zero watermark', function (): void {
    feed('/api/products/snapshot')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.watermark', '0');
    feed('/api/products/changes?since=0')->assertOk()->assertJsonPath('data', []);
});

it('applies the rate limit', function (): void {
    config(['trace.source.rate_limit' => 2]);

    feed('/api/products/snapshot')->assertOk();
    feed('/api/products/snapshot')->assertOk();
    feed('/api/products/snapshot')->assertStatus(429);
});
