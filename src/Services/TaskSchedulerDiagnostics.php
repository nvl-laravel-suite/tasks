<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\LockProvider;
use Nvl\Activity\Providers\ActivityServiceProvider;
use Nvl\Support\Integrations\OptionalIntegration;
use Nvl\Tasks\Contracts\TaskSchedulerReadiness;
use Throwable;

/** Checks observable schedule execution and the native scheduler's lock backend. */
final readonly class TaskSchedulerDiagnostics
{
    /** Resolve integrations lazily while retaining host-replaceable health evidence. */
    public function __construct(private Factory $cache, private TaskSchedulerReadiness $readiness, private OptionalIntegration $integrations) {}

    /** @return list<array{key: string, severity: string, passed: bool, message: string}> */
    public function inspect(): array
    {
        if (! $this->integrations->enabled('nvl-tasks.activity.enabled', ActivityServiceProvider::class)
            || config('nvl-tasks.activity.schedule.enabled', true) !== true) {
            return [['key' => 'scheduler.enabled', 'severity' => 'info', 'passed' => true, 'message' => 'The Tasks activity correctness schedule is inactive.']];
        }
        $locks = false;
        $running = false;
        try {
            $locks = $this->cache->store()->getStore() instanceof LockProvider;
        } catch (Throwable) {
            // Backend initialization failure is reported as a failed lock check.
        }
        try {
            $running = $this->readiness->running();
        } catch (Throwable) {
            // Unavailable health evidence never establishes readiness.
        }

        return [
            ['key' => 'scheduler.atomic_locks', 'severity' => 'error', 'passed' => $locks, 'message' => $locks
                ? 'The native scheduler cache supports atomic locks.'
                : 'Tasks outbox onOneServer requires an effective cache store with atomic locks.'],
            ['key' => 'scheduler.running', 'severity' => 'warning', 'passed' => $running, 'message' => $running
                ? 'Recent correctness schedule execution has been observed.'
                : 'No fresh Tasks scheduler heartbeat. Run Laravel schedule:run every minute with a shared cache, or bind TaskSchedulerReadiness to monitored scheduler health.'],
        ];
    }
}
