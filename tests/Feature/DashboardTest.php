<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Vortech\Trace\Enums\CursorType;
use Vortech\Trace\Jobs\TraceSyncJob;
use Vortech\Trace\Models\TraceMeta;
use Vortech\Trace\Models\TraceSyncRun;
use Vortech\Trace\Source\Cursor;
use Vortech\Trace\Tests\Support\RemoteProduct;

beforeEach(function (): void {
    config(['trace.remote.models' => [RemoteProduct::class]]);
});

function openDashboard(): void
{
    Gate::define('viewTrace', fn (?object $user = null): bool => true);
    Gate::define('manageTrace', fn (?object $user = null): bool => true);
}

function onlyViewing(): void
{
    Gate::define('viewTrace', fn (?object $user = null): bool => true);
    Gate::define('manageTrace', fn (?object $user = null): bool => false);
}

describe('access', function (): void {
    it('is hidden outside the local environment until the gates are defined', function (): void {
        $this->get('/trace/remote')->assertNotFound();
        $this->getJson('/trace/api/remote')->assertNotFound();
        $this->getJson('/trace/api/source')->assertNotFound();

        Gate::define('viewTrace', fn (?object $user = null): bool => true);

        $this->get('/trace/remote')->assertOk();
        $this->getJson('/trace/api/remote')->assertOk();
    });

    it('lets a guest in only when the gate allows it', function (): void {
        Gate::define('viewTrace', fn (?object $user = null): bool => $user !== null);

        $this->get('/trace/health')->assertNotFound();
        $this->getJson('/trace/api/health')->assertNotFound();
    });

    it('keeps the actions behind the manageTrace gate', function (): void {
        onlyViewing();
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->getJson('/trace/api/remote')->assertOk()->assertJsonPath('canManage', false);
        $this->getJson('/trace/api/source')->assertOk()->assertJsonPath('canManage', false);

        foreach (['remote/sync', 'remote/reconcile', 'source/prune', 'source/triggers'] as $action) {
            $this->postJson("/trace/api/{$action}", ['model' => RemoteProduct::class])->assertNotFound();
        }
    });
});

describe('the page', function (): void {
    it('serves the application with the bundle inlined and the values the browser needs', function (): void {
        openDashboard();

        $html = $this->get('/trace/runs')->assertOk()->getContent();

        expect($html)->toContain('<div id="app"></div>')
            ->toContain('window.__TRACE__')
            ->toContain('"base":"\/trace"')
            ->toContain('"api":"\/trace\/api"')
            ->toContain('"csrf":"')
            ->toContain(file_get_contents(__DIR__.'/../../dist/trace.css'));
    });

    it('serves every page of the application and nothing else below the path', function (): void {
        openDashboard();

        foreach (['', '/remote', '/runs', '/source', '/health'] as $page) {
            $this->get("/trace{$page}")->assertOk();
        }

        $this->get('/trace/elsewhere')->assertNotFound();
    });

    it('opens the side the application uses', function (): void {
        openDashboard();

        expect($this->get('/trace')->getContent())->toContain('"home":"remote"');
    });

    it('opens the source side when nothing is synced', function (): void {
        openDashboard();
        config(['trace.remote.models' => []]);
        Schema::dropIfExists('trace_sync_runs');
        Schema::dropIfExists('trace_sync_states');
        Schema::dropIfExists('trace_links');

        expect($this->get('/trace')->getContent())->toContain('"home":"source"');
    });
});

describe('remote', function (): void {
    it('lists a model that never synced', function (): void {
        openDashboard();

        $this->getJson('/trace/api/remote')
            ->assertOk()
            ->assertJsonPath('models.0.name', 'RemoteProduct')
            ->assertJsonPath('models.0.status', 'Never synced')
            ->assertJsonPath('models.0.lastSuccess', null)
            ->assertJsonPath('stats.models', 1)
            ->assertJsonPath('canManage', true);
    });

    it('describes a synced model with its links, cursor and runs', function (): void {
        openDashboard();
        makeProduct();
        makeProduct();
        syncProducts();

        $this->getJson('/trace/api/remote')
            ->assertOk()
            ->assertJsonPath('models.0.status', 'In sync')
            ->assertJsonPath('models.0.synced', true)
            ->assertJsonPath('models.0.links', 2)
            ->assertJsonPath('models.0.runs.0.mode', 'Bootstrap')
            ->assertJsonPath('stats.synced', 1)
            ->assertJsonPath('stats.links', 2)
            ->assertJsonStructure(['models' => [['class', 'type', 'name', 'feed', 'tone', 'cursor', 'schema', 'instance', 'note', 'lastSuccess', 'runs']]]);
    });

    it('queues a sync from the dashboard', function (): void {
        openDashboard();
        $this->withoutMiddleware(PreventRequestForgery::class);
        Queue::fake();

        $this->postJson('/trace/api/remote/sync', ['model' => RemoteProduct::class])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', 'Sync queued for RemoteProduct');

        Queue::assertPushed(TraceSyncJob::class);
    });

    it('runs a sync without the queue and reports the result', function (): void {
        openDashboard();
        $this->withoutMiddleware(PreventRequestForgery::class);
        Queue::fake();
        makeProduct();

        $this->postJson('/trace/api/remote/sync', ['model' => RemoteProduct::class, 'inline' => true])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'finished') && str_contains($message, '+1'));

        Queue::assertNothingPushed();
        expect(RemoteProduct::query()->count())->toBe(1);
    });

    it('reconciles from the dashboard', function (): void {
        openDashboard();
        $this->withoutMiddleware(PreventRequestForgery::class);
        makeProduct();
        syncProducts();

        $this->postJson('/trace/api/remote/reconcile', ['model' => RemoteProduct::class, 'inline' => true])->assertJsonPath('ok', true);

        expect(TraceSyncRun::query()->where('mode', 'reconcile')->count())->toBe(1);
    });

    it('shows why an immediate run failed', function (): void {
        openDashboard();
        $this->withoutMiddleware(PreventRequestForgery::class);
        config(['trace.remote.default.token' => null]);

        $this->postJson('/trace/api/remote/sync', ['model' => RemoteProduct::class, 'inline' => true])
            ->assertOk()
            ->assertJsonPath('ok', false);
    });

    it('refuses to sync a class that is not a synced model', function (): void {
        openDashboard();
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->postJson('/trace/api/remote/sync', ['model' => \DateTime::class])->assertNotFound();
    });
});

describe('runs', function (): void {
    it('lists the sync runs, newest first, with their result', function (): void {
        openDashboard();
        makeProduct();
        syncProducts();
        makeProduct();
        syncProducts();

        $this->getJson('/trace/api/runs')
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('total', 2)
            ->assertJsonPath('rows.0.mode', 'Incremental')
            ->assertJsonPath('rows.0.summary', '+1  ~0  −0')
            ->assertJsonPath('rows.1.mode', 'Bootstrap')
            ->assertJsonPath('rows.0.model', 'RemoteProduct')
            ->assertJsonPath('stats.all', 2)
            ->assertJsonPath('statuses', ['running', 'completed', 'failed'])
            ->assertJsonPath('modes', ['bootstrap', 'incremental', 'reconcile']);
    });

    it('filters the runs by status, mode and model', function (): void {
        openDashboard();
        makeProduct();
        syncProducts();
        syncProducts();

        $this->getJson('/trace/api/runs?status=failed')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/trace/api/runs?mode=bootstrap')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('filters.mode', 'bootstrap');
        $this->getJson('/trace/api/runs?model='.urlencode(RemoteProduct::class))->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/trace/api/runs?status=nonsense')->assertOk()->assertJsonPath('total', 2)->assertJsonPath('filters.status', '');
    });

    it('pages the runs', function (): void {
        openDashboard();
        makeProduct();
        syncProducts();

        foreach (range(1, 30) as $i) {
            TraceSyncRun::query()->create(['feed' => 'products', 'model_type' => RemoteProduct::class, 'mode' => 'incremental', 'status' => 'completed', 'started_at' => now(), 'finished_at' => now()]);
        }

        $this->getJson('/trace/api/runs')->assertJsonPath('pages', 2)->assertJsonPath('page', 1)->assertJsonCount(25, 'rows');
        $this->getJson('/trace/api/runs?page=2')->assertJsonPath('page', 2)->assertJsonCount(6, 'rows');
        $this->getJson('/trace/api/runs?page=99')->assertJsonPath('page', 2);
    });

    it('answers for an application without remote tables', function (): void {
        openDashboard();
        Schema::dropIfExists('trace_sync_runs');

        $this->getJson('/trace/api/runs')->assertOk()->assertJsonPath('available', false);
    });
});

describe('source', function (): void {
    it('lists the published feeds with their capture mode', function (): void {
        openDashboard();
        makeProduct();

        $response = $this->getJson('/trace/api/source')->assertOk();

        expect(collect($response->json('feeds'))->pluck('capture', 'feed')->all())->toMatchArray(['products' => 'Model events', 'db-products' => 'Database triggers'])
            ->and($response->json('hours'))->toHaveCount(24)
            ->and($response->json('prune'))->toBeNull()
            ->and($response->json('selected'))->toBe('products');
    });

    it('flags database triggers that are missing and clears the flag once installed', function (): void {
        openDashboard();

        $feed = fn (): array => collect($this->getJson('/trace/api/source')->json('feeds'))->firstWhere('feed', 'db-products');

        expect($feed()['status'])->toBe('Triggers need attention')
            ->and($feed()['issue'])->toContain('Database triggers are missing');

        installTriggers();

        expect($feed()['issue'])->toBeNull()->and($feed()['status'])->toBe('Triggers installed');
    });

    it('counts the changes of the last hours of the selected feed', function (): void {
        openDashboard();
        makeProduct();
        makeProduct();

        $response = $this->getJson('/trace/api/source?feed=products')->assertOk();

        expect(collect($response->json('hours'))->sum('count'))->toBe(2)
            ->and($response->json('stats.today'))->toBeGreaterThanOrEqual(2);
    });

    it('remembers the last prune', function (): void {
        openDashboard();
        $this->artisan('trace:prune')->assertSuccessful();

        expect(TraceMeta::query()->whereKey('prune.last')->exists())->toBeTrue();

        $this->getJson('/trace/api/source')->assertJsonPath('prune.changes', 0)->assertJsonStructure(['prune' => ['at', 'changes', 'runs']]);
    });

    it('answers for an application without source tables', function (): void {
        openDashboard();
        Schema::dropIfExists('trace_changes');
        Schema::dropIfExists('trace_streams');
        Schema::dropIfExists('trace_meta');

        $this->getJson('/trace/api/source')->assertOk()->assertJsonPath('feeds', [])->assertJsonPath('instance', '');
    });

    it('prunes from the dashboard and reports the result', function (): void {
        openDashboard();
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->postJson('/trace/api/source/prune')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Pruned'));
    });

    it('installs the triggers from the dashboard', function (): void {
        openDashboard();
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->postJson('/trace/api/source/triggers')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'installed'));

        $feed = collect($this->getJson('/trace/api/source')->json('feeds'))->firstWhere('feed', 'db-products');

        expect($feed['issue'])->toBeNull();
    });
});

describe('health', function (): void {
    it('runs the doctor checks', function (): void {
        openDashboard();

        $response = $this->getJson('/trace/api/health')->assertOk()->assertJsonPath('live', false);

        expect(collect($response->json('checks'))->pluck('label')->all())->toContain('Source tables exist', 'Cache store supports atomic locks')
            ->and(collect($response->json('checks'))->pluck('status')->unique()->all())->each->toBeIn(['passed', 'failed', 'skipped']);
    });
});

it('reads a stored cursor for display without trusting it', function (): void {
    $token = (new Cursor(CursorType::Changes, 'products', 'instance', 42))->encode();

    expect(Cursor::peek($token))->toBe(['type' => 'changes', 'version' => 42, 'lastKey' => null])
        ->and(Cursor::peek(null))->toBeNull()
        ->and(Cursor::peek('garbage'))->toBeNull();
});
