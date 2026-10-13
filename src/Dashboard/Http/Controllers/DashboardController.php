<?php

declare(strict_types=1);

namespace Vortech\Trace\Dashboard\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use RuntimeException;
use Vortech\Trace\Dashboard\Queries\RemoteOverview;
use Vortech\Trace\Dashboard\Queries\SourceOverview;
use Vortech\Trace\Support\TraceConfig;

final readonly class DashboardController
{
    public function __construct(
        private Factory $views,
        private RemoteOverview $remote,
        private SourceOverview $source,
    ) {}

    public function __invoke(): View
    {
        $home = $this->remote->models() !== [] || $this->source->feeds() === [] ? 'remote' : 'source';

        return $this->views->make('trace::dashboard.app', [
            'script' => str_replace('</script', '<\\/script', $this->asset('trace.js')),
            'style' => $this->asset('trace.css'),
            'boot' => [
                'base' => '/'.TraceConfig::dashboardPath(),
                'api' => '/'.TraceConfig::dashboardPath().'/api',
                'csrf' => csrf_token(),
                'app' => config()->string('app.name', 'Laravel Trace'),
                'env' => app()->environment(),
                'home' => $home,
            ],
        ]);
    }

    private function asset(string $file): string
    {
        $path = dirname(__DIR__, 4).'/dist/'.$file;
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new RuntimeException("The dashboard asset [dist/{$file}] is missing: run npm run build in the package.");
        }

        return $contents;
    }
}
