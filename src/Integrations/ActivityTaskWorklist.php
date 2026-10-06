<?php

declare(strict_types=1);

namespace Nvl\Tasks\Integrations;

use Nvl\Activity\Contracts\ActivityTenantWorklist;
use Nvl\Tasks\Contracts\TaskActivityWorklist;

/** Delegates task recovery enumeration to the reviewed Activity worklist. */
final readonly class ActivityTaskWorklist implements TaskActivityWorklist
{
    /** Create the active recovery enumeration adapter. */
    public function __construct(private ActivityTenantWorklist $worklist) {}

    /**
     * Return the active Activity tenant identifiers.
     *
     * @return list<string>
     */
    public function activeTenantIds(): array
    {
        return $this->worklist->activeTenantIds();
    }
}
