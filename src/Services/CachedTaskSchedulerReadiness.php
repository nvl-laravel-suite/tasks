<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use Illuminate\Contracts\Cache\Factory;
use Nvl\Tasks\Contracts\TaskSchedulerReadiness;

/** Observes actual schedule execution through a short-lived cache heartbeat. */
final readonly class CachedTaskSchedulerReadiness implements TaskSchedulerReadiness
{
    public const string CACHE_KEY = 'nvl:tasks:scheduler-heartbeat';

    private const int MAX_AGE_SECONDS = 180;

    /** Retain Laravel's effective scheduling cache factory. */
    public function __construct(private Factory $cache) {}

    /** Record execution after the scheduler obtains the event's mutex. */
    public function recordHeartbeat(): void
    {
        $this->cache->store()->put(self::CACHE_KEY, now()->getTimestamp(), self::MAX_AGE_SECONDS + 60);
    }

    /** Verify a recent heartbeat, rejecting malformed and future values. */
    public function running(): bool
    {
        $heartbeat = $this->cache->store()->get(self::CACHE_KEY);
        $current = now()->getTimestamp();

        return is_int($heartbeat) && $heartbeat <= $current && $heartbeat >= $current - self::MAX_AGE_SECONDS;
    }
}
