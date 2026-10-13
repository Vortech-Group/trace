<?php

declare(strict_types=1);

namespace Vortech\Trace\Console;

use Illuminate\Console\Command;
use Throwable;
use Vortech\Trace\Capture\TriggerDefinition;
use Vortech\Trace\Capture\TriggerManager;
use Vortech\Trace\Enums\TriggerStatus;

final class TriggersCommand extends Command
{
    protected $signature = 'trace:triggers {action=status : install, remove or status} {--model=* : A model class to include besides the registered feeds} {--sql : Print the statements instead of running them}';

    protected $description = 'Install, remove or inspect the database triggers of the models that use CaptureMode::Database';

    /**
     * @throws Throwable
     */
    public function handle(TriggerManager $triggers): int
    {
        $action = $this->argument('action');

        if (! in_array($action, ['install', 'remove', 'status'], true)) {
            $this->components->error('The action must be install, remove or status.');

            return self::FAILURE;
        }

        $definitions = $this->definitions($triggers);

        if (blank($definitions)) {
            $this->components->info('No model uses CaptureMode::Database.');

            return self::SUCCESS;
        }

        foreach ($definitions as $definition) {
            $label = $definition->modelClass.' ('.$definition->physicalTable().')';

            if ($this->option('sql') === true) {
                $this->line("-- {$label}");

                foreach ($triggers->statements($definition) as $statement) {
                    $this->line($statement.';');
                }

                continue;
            }

            match ($action) {
                'install' => $this->install($triggers, $definition, $label),
                'remove' => $this->remove($triggers, $definition, $label),
                default => $this->status($triggers, $definition, $label),
            };
        }

        return self::SUCCESS;
    }

    /**
     * @return list<TriggerDefinition>
     *
     * @throws Throwable
     */
    private function definitions(TriggerManager $triggers): array
    {
        $definitions = [];

        foreach ($triggers->definitions() as $definition) {
            $definitions[$definition->modelClass] = $definition;
        }

        $models = $this->option('model');

        foreach (is_array($models) ? $models : [] as $model) {
            if (is_string($model) && class_exists($model)) {
                $definitions[$model] = $triggers->definitionFor($model);
            }
        }

        return array_values($definitions);
    }

    /**
     * @throws Throwable
     */
    private function install(TriggerManager $triggers, TriggerDefinition $definition, string $label): void
    {
        $triggers->install($definition);

        $this->components->twoColumnDetail($label, '<fg=green>installed</>');
    }

    /**
     * @throws Throwable
     */
    private function remove(TriggerManager $triggers, TriggerDefinition $definition, string $label): void
    {
        $triggers->remove($definition);

        $this->components->twoColumnDetail($label, '<fg=yellow>removed</>');
    }

    /**
     * @throws Throwable
     */
    private function status(TriggerManager $triggers, TriggerDefinition $definition, string $label): void
    {
        $status = $triggers->status($definition);

        $this->components->twoColumnDetail($label, match ($status) {
            TriggerStatus::Installed => '<fg=green>installed</>',
            TriggerStatus::Outdated => '<fg=yellow>outdated, run trace:triggers install</>',
            TriggerStatus::Missing => '<fg=red>missing, run trace:triggers install</>',
        });
    }
}
