<?php

declare(strict_types=1);

namespace Nvl\Tasks\Console;

use Illuminate\Console\Command;
use Nvl\Tasks\Services\TasksActivityDrainDispatcher;

/** Recover task activity delivery when dispatch or prior attempts failed. */
final class DrainTaskActivityOutboxCommand extends Command
{
    protected $signature = 'nvl:tasks:activity:drain {--limit= : Maximum events to inspect per tenant}';

    protected $description = 'Deliver due task Activity events from the durable outbox';

    /** Construct the recovery command. */
    public function __construct(private readonly TasksActivityDrainDispatcher $dispatcher)
    {
        parent::__construct();
    }

    /** Deliver one bounded sweep. */
    public function handle(): int
    {
        $option = $this->option('limit');
        $configured = config('nvl-tasks.activity.drain_limit', 100);
        $limit = $option === null ? $configured : (
            $option !== '' && (string) (int) $option === $option
                ? (int) $option
                : null
        );

        if (! is_int($limit) || $limit < 1 || $limit > 1000) {
            $this->error('The limit must be an integer from 1 to 1000.');

            return self::FAILURE;
        }

        $delivered = $this->dispatcher->drain($limit);
        $this->info("Delivered {$delivered} task Activity event(s).");

        return self::SUCCESS;
    }
}
