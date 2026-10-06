<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use Illuminate\Contracts\Config\Repository;
use Nvl\Support\Tenancy\Contracts\TenantContext;
use Nvl\Support\Tenancy\Contracts\TenantRunner;
use Nvl\Support\Tenancy\Enums\TenantContextMode;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Support\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Support\Tenancy\ValueObjects\TenantId;
use Nvl\Tasks\Contracts\TaskActivityWorklist;

/** Sweep due task activity in every reviewed active tenant scope. */
final readonly class TasksActivityDrainDispatcher
{
    /** Construct the bounded sweep. */
    public function __construct(
        private Repository $config,
        private TenantContext $context,
        private TenantRunner $tenants,
        private TaskActivityWorklist $worklist,
        private TasksActivityDelivery $delivery,
    ) {}

    /** Return the number of events delivered during this sweep. */
    public function drain(int $limit): int
    {
        if ($this->config->get('nvl-tenancy.enabled') !== true) {
            return $this->delivery->drain($limit);
        }

        $snapshot = $this->context->snapshot();

        if ($snapshot->mode === TenantContextMode::Tenant) {
            return $this->delivery->drain($limit);
        }

        if (! in_array($snapshot->mode, [TenantContextMode::Unresolved, TenantContextMode::Platform], true)) {
            throw new TenantBoundaryViolation('Task activity delivery requires an admitted tenant or platform worklist.');
        }

        $ids = $snapshot->mode === TenantContextMode::Platform
            ? $this->worklist->activeTenantIds()
            : $this->tenants->platform(
                new PlatformOperation('tasks.activity.enumerate', 'system', 'task-activity-delivery'),
                fn (): array => $this->worklist->activeTenantIds(),
            );
        $delivered = 0;

        foreach ($ids as $id) {
            $delivered += $this->tenants->run(new TenantId($id), fn (): int => $this->delivery->drain($limit));
        }

        return $delivered;
    }
}
