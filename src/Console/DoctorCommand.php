<?php

declare(strict_types=1);

namespace Vortech\Trace\Console;

use Illuminate\Console\Command;
use Vortech\Trace\Enums\CheckStatus;
use Vortech\Trace\Health\Check;
use Vortech\Trace\Health\HealthChecker;

final class DoctorCommand extends Command
{
    protected $signature = 'trace:doctor {--offline : Skip the request to the source}';

    protected $description = 'Validate the Trace setup of the source and the remote side';

    public function handle(HealthChecker $health): int
    {
        $this->components->twoColumnDetail('<fg=gray>Check</>', '<fg=gray>Result</>');

        $checks = [...$health->source(), ...$health->remote($this->option('offline') !== true)];

        foreach ($checks as $check) {
            $this->components->twoColumnDetail($check->label, $this->render($check));
        }

        $failures = count(array_filter($checks, fn (Check $check): bool => $check->failed()));

        if ($failures > 0) {
            $this->components->error("{$failures} check(s) failed.");

            return self::FAILURE;
        }

        $this->components->info('Everything looks fine.');

        return self::SUCCESS;
    }

    private function render(Check $check): string
    {
        $color = match ($check->status) {
            CheckStatus::Passed => 'green',
            CheckStatus::Failed => 'red',
            CheckStatus::Skipped => 'gray',
        };

        return "<fg={$color}>{$check->detail}</>";
    }
}
