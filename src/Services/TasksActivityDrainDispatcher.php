<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use Nvl\Support\Ownership\Contracts\OwnershipSystemRunner;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Services\OwnershipContextResolver;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Tasks\Ownership\TaskOutboxOwnerWorklist;

/**
 * Recovers task audit facts inside separately authorized canonical owner scopes.
 *
 * @internal
 */
final readonly class TasksActivityDrainDispatcher
{
    /** Retain only neutral admission contracts and the bounded task recovery capability. */
    public function __construct(
        private OwnershipResourceRegistry $resources,
        private OwnershipContextResolver $context,
        private OwnershipSystemRunner $owners,
        private TaskOutboxOwnerWorklist $worklist,
        private TasksActivityDelivery $delivery,
    ) {}

    /** Deliver a bounded page per owner without borrowing request or tenant authority. */
    public function drain(int $limit): int
    {
        if ($this->resources->get('tasks.activity_outbox')->ownership->strategy === 'unscoped') {
            return $this->delivery->drain($limit);
        }
        if ($this->context->current()->snapshot()->mode === OwnershipContextMode::Owner) {
            return $this->delivery->drain($limit);
        }
        $delivered = 0;
        foreach ($this->worklist->owners() as $owner) {
            $delivered += $this->owners->runSystem($owner, 'tasks.activity-drain', fn (): int => $this->delivery->drain($limit));
        }

        return $delivered;
    }
}
