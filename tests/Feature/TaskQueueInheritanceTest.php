<?php

declare(strict_types=1);

use Nvl\Support\Tenancy\Enums\TenantContextMode;
use Nvl\Support\Tenancy\ValueObjects\TenantContextSnapshot;
use Nvl\Support\Tenancy\ValueObjects\TenantJobEnvelope;
use Nvl\Tasks\Jobs\ProcessTaskActivityOutboxJob;

it('inherits task delivery queue selection without discarding its captured envelope', function (array $overrides, string $connection, string $queue): void {
    config([
        'nvl-tasks.queue' => ['connection' => null, 'name' => null], 'nvl-tasks.activity.queue' => null,
        'nvl-core.queue' => ['connection' => null, 'name' => null],
        'queue.default' => 'host', 'queue.connections.host.queue' => 'host-work', ...$overrides,
    ]);
    $envelope = new TenantJobEnvelope(new TenantContextSnapshot(TenantContextMode::Disabled));
    $job = new ProcessTaskActivityOutboxJob('event', $envelope);
    expect($job->connection)->toBe($connection)->and($job->queue)->toBe($queue)
        ->and($job->tenantJobEnvelope())->toBe($envelope)->and($job->afterCommit)->toBeTrue();
})->with([
    'Laravel' => [[], 'host', 'host-work'],
    'Core' => [['nvl-core.queue' => ['connection' => 'suite', 'name' => 'suite-work']], 'suite', 'suite-work'],
    'package' => [['nvl-tasks.queue' => ['connection' => 'tasks', 'name' => 'tasks-work']], 'tasks', 'tasks-work'],
    'explicit sync' => [['nvl-tasks.queue' => ['connection' => 'sync', 'name' => 'inline']], 'sync', 'inline'],
    'legacy explicit override' => [['nvl-tasks.activity.queue' => 'old-work'], 'host', 'old-work'],
]);
