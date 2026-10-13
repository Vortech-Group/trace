<?php

declare(strict_types=1);

namespace Vortech\Trace\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;
use Vortech\Trace\Source\InstanceId;
use Vortech\Trace\TraceServiceProvider;

use function Laravel\Prompts\multiselect;

#[Signature('trace:install
    {--source : Prepare this application to publish data}
    {--remote : Prepare this application to receive data}
    {--force : Overwrite the existing configuration file}')]
#[Description('Publish the Trace configuration and migrations for the source and/or the remote side')]
final class InstallCommand extends Command
{
    /**
     * @throws Throwable
     */
    public function handle(): int
    {
        $source = $this->option('source') === true;
        $remote = $this->option('remote') === true;

        if (! $source && ! $remote && $this->input->isInteractive()) {
            $sides = multiselect(
                label: 'Which side does this application play?',
                options: ['source' => 'Source: publishes its data', 'remote' => 'Remote: receives data from a source'],
                required: true,
                hint: 'Space selects, enter confirms. Pick both if it does both.',
            );

            $source = in_array('source', $sides, true);
            $remote = in_array('remote', $sides, true);
        }

        if (! $source && ! $remote) {
            $this->components->error('Choose a side: --source, --remote or both.');

            return self::FAILURE;
        }

        $this->publish('trace-config');

        if ($source) {
            $this->installSource();
        }

        if ($remote) {
            $this->publishMigration('trace-remote-migrations', 'create_trace_remote_tables');
            $this->components->info('Remote prepared. Set TRACE_REMOTE_URL and TRACE_REMOTE_TOKEN, run "php artisan migrate", then add #[Sync] to your models.');
        }

        return self::SUCCESS;
    }

    /**
     * @throws Throwable
     */
    private function installSource(): void
    {
        $this->publishMigration('trace-source-migrations', 'create_trace_source_tables');

        if (Schema::hasTable('trace_meta')) {
            $this->components->info('Source instance id: '.InstanceId::get());
        } else {
            $this->components->warn('Run "php artisan migrate", then "php artisan trace:install --source" again to create the instance id (it is also created on first use).');
        }

        if (blank(config('trace.source.token'))) {
            $this->components->warn('TRACE_SOURCE_TOKEN is not set, the feed stays closed. For example: TRACE_SOURCE_TOKEN='.Str::random(64));
        }

        $this->components->info('Source prepared. Mark models with #[Traceable] and register feeds with Route::source().');
    }

    private function publishMigration(string $tag, string $name): void
    {
        $published = glob(database_path("migrations/*_{$name}.php"));

        if ($published !== false && filled($published)) {
            $this->components->warn("The {$name} migration is already published.");

            return;
        }

        $this->publish($tag);
    }

    private function publish(string $tag): void
    {
        $this->call('vendor:publish', [
            '--provider' => TraceServiceProvider::class,
            '--tag' => $tag,
            '--force' => $this->option('force'),
        ]);
    }
}
