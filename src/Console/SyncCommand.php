<?php

declare(strict_types=1);

namespace Vortech\Trace\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Throwable;
use Vortech\Trace\Facades\Remote;

final class SyncCommand extends Command
{
    protected $signature = 'trace:sync
    {model : The fully qualified class name of the model}
    {--queue : Dispatch the sync to the queue instead of running it now}
    {--full : Reconcile the complete state instead of only pulling the changes}
    {--allow-mass-deletion : Allow a reconcile to soft delete more than the configured share of records}
    {--dry-run : Read the source and show what the run would do, write nothing}';

    protected $description = 'Synchronize a #[Sync] model with its source';

    public function handle(): int
    {
        $model = $this->argument('model');

        if (! is_string($model) || ! class_exists($model) || ! is_subclass_of($model, Model::class)) {
            $this->components->error('The model must be an existing Eloquent model class.');

            return self::FAILURE;
        }

        $dryRun = $this->option('dry-run') === true;

        if ($dryRun && $this->option('queue') === true) {
            $this->components->error('A dry run cannot be queued: it has to report its result here.');

            return self::FAILURE;
        }

        try {
            $builder = Remote::for($model);
            $pending = $this->option('full') === true ? $builder->reconcile() : $builder->sync();
            $pending->allowMassDeletion($this->option('allow-mass-deletion') === true)->dryRun($dryRun);

            if ($this->option('queue') === true) {
                $pending->dispatch();
                $this->components->info("Sync of {$model} queued.");

                return self::SUCCESS;
            }

            $result = $pending->run();
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->components->info("Dry run of a {$result->mode->value} in {$result->durationMs} ms: nothing was written.");
        } else {
            $this->components->info("{$result->mode->value} finished in {$result->durationMs} ms.");
        }

        $this->table(
            ['Created', 'Matched', 'Updated', 'Unchanged', 'Deleted', 'Restored'],
            [[$result->created, $result->matched, $result->updated, $result->unchanged, $result->deleted, $result->restored]],
        );

        if ($dryRun) {
            $this->components->twoColumnDetail('Created', 'records without a local row: they become new rows');
            $this->components->twoColumnDetail('Matched', 'records that found an existing local row through match() (counted in Updated or Unchanged)');

            if ($result->created > 0 && blank(Remote::for($model)->definition()->mapper->match())) {
                $this->components->warn('The mapper declares no match(): existing local rows can never be matched, every record becomes a new row.');
            }
        }

        return self::SUCCESS;
    }
}
