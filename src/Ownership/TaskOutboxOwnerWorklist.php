<?php

declare(strict_types=1);

namespace Nvl\Tasks\Ownership;

use Carbon\CarbonImmutable;
use Nvl\Support\Ownership\Contracts\OwnershipReadiness;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;
use Nvl\Tasks\Models\TaskActivityOutbox;

/**
 * Projects only due outbox owner identities; each identity still requires fresh system admission.
 *
 * @internal
 */
final readonly class TaskOutboxOwnerWorklist
{
    /** Validate live source markers before this fixed cross-owner maintenance projection. */
    public function __construct(private OwnershipReadiness $readiness) {}

    /** @return iterable<OwnerReference> */
    public function owners(): iterable
    {
        $this->readiness->assertActive(TaskActivityOutbox::TENANT_RESOURCE);
        $model = new TaskActivityOutbox;
        $seen = [];
        $rows = $model->getConnection()->table($model->getTable())->useWritePdo()
            ->whereNull('delivered_at')->where('available_at', '<=', CarbonImmutable::now())
            ->select(['id', 'owner_scope_key', 'owner_reference'])->orderBy('id')->lazyById(100);
        foreach ($rows as $row) {
            if (! is_string($row->owner_reference) || ! is_string($row->owner_scope_key)) {
                throw new OwnershipViolation('A pending outbox requires a complete canonical owner reference.');
            }
            $owner = OwnerReference::fromJson($row->owner_reference);
            if ($owner->scopeKey() !== $row->owner_scope_key) {
                throw new OwnershipViolation('The outbox owner reference and physical partition differ.');
            }
            if (! isset($seen[$row->owner_scope_key])) {
                $seen[$row->owner_scope_key] = true;
                yield $owner;
            }
        }
    }
}
