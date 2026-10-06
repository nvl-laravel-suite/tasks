<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Nvl\Activity\Facades\ActivityLog;
use Nvl\Activity\Providers\ActivityServiceProvider;
use Nvl\Activity\Support\ActivityRecordEnvelope;
use Nvl\Support\Integrations\OptionalIntegration;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Tasks\Models\TaskActivityOutbox;
use Nvl\Tasks\Support\TasksConfiguration;
use Throwable;

/** Delivers committed task events with a short lease and idempotent Activity IDs. */
final readonly class TasksActivityDelivery
{
    private const int LEASE_SECONDS = 120;

    /** Construct the tenant-bounded delivery service. */
    public function __construct(private TenantBoundary $boundary, private OptionalIntegration $integrations) {}

    /** Attempt one due event; another worker may already hold its lease. */
    public function deliver(string $id): bool
    {
        if (! $this->integrations->enabled('tasks.activity.enabled', ActivityServiceProvider::class)) {
            return false;
        }

        $claim = DB::connection(TasksConfiguration::connection())->transaction(function () use ($id): array|bool|null {
            $event = $this->boundary->query(TaskActivityOutbox::query(), TaskActivityOutbox::TENANT_RESOURCE)
                ->whereKey($id)->lockForUpdate()->first();
            $now = CarbonImmutable::now();

            if ($event instanceof TaskActivityOutbox && $event->delivered_at !== null) {
                return true;
            }

            if (! $event instanceof TaskActivityOutbox || $event->available_at->isFuture()
                || ($event->leased_until !== null && $event->leased_until->isAfter($now))) {
                return null;
            }

            $token = (string) Str::uuid();
            $event->forceFill([
                'lease_token' => $token,
                'leased_until' => $now->addSeconds(self::LEASE_SECONDS),
                'attempts' => $event->attempts + 1,
            ])->save();

            return ['token' => $token, 'payload' => $event->payload, 'attempts' => $event->attempts];
        });

        if ($claim === null || $claim === true) {
            return $claim === true;
        }

        try {
            ActivityLog::recordEnvelope(ActivityRecordEnvelope::fromArray($claim['payload']));
        } catch (Throwable $exception) {
            $this->releaseAfterFailure($id, $claim['token'], $claim['attempts'], $exception);

            return false;
        }

        DB::connection(TasksConfiguration::connection())->transaction(function () use ($id, $claim): void {
            $event = $this->boundary->query(TaskActivityOutbox::query(), TaskActivityOutbox::TENANT_RESOURCE)
                ->whereKey($id)->where('lease_token', $claim['token'])->lockForUpdate()->first();

            if ($event instanceof TaskActivityOutbox) {
                $event->forceFill([
                    'delivered_at' => CarbonImmutable::now(),
                    'lease_token' => null,
                    'leased_until' => null,
                    'last_error' => null,
                ])->save();
            }
        });

        return true;
    }

    /** Deliver a bounded page of due events within the current tenant scope. */
    public function drain(int $limit): int
    {
        if (! $this->integrations->enabled('tasks.activity.enabled', ActivityServiceProvider::class)) {
            return 0;
        }

        $ids = $this->boundary->query(TaskActivityOutbox::query(), TaskActivityOutbox::TENANT_RESOURCE)
            ->whereNull('delivered_at')
            ->where('available_at', '<=', CarbonImmutable::now())
            ->where(static function ($query): void {
                $query->whereNull('leased_until')->orWhere('leased_until', '<=', CarbonImmutable::now());
            })
            ->orderBy('available_at')
            ->limit(max(1, min($limit, 1000)))
            ->pluck('id');

        $delivered = 0;

        foreach ($ids as $id) {
            if (is_string($id) && $this->deliver($id)) {
                $delivered++;
            }
        }

        return $delivered;
    }

    /** Release a failed lease while retaining the immutable event for retry. */
    private function releaseAfterFailure(string $id, string $token, int $attempts, Throwable $exception): void
    {
        try {
            DB::connection(TasksConfiguration::connection())->transaction(function () use ($id, $token, $attempts, $exception): void {
                $event = $this->boundary->query(TaskActivityOutbox::query(), TaskActivityOutbox::TENANT_RESOURCE)
                    ->whereKey($id)->where('lease_token', $token)->lockForUpdate()->first();

                if ($event instanceof TaskActivityOutbox) {
                    $seconds = min(3600, 5 * (2 ** min($attempts - 1, 10)));
                    $event->forceFill([
                        'available_at' => CarbonImmutable::now()->addSeconds($seconds),
                        'lease_token' => null,
                        'leased_until' => null,
                        'last_error' => $exception::class,
                    ])->save();
                }
            });
        } finally {
            Log::warning('Task activity delivery failed; the outbox event remains pending.', [
                'event_id' => $id,
                'attempts' => $attempts,
                'exception' => $exception,
            ]);
        }
    }
}
