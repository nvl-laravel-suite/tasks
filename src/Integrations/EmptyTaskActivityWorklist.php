<?php

declare(strict_types=1);

namespace Nvl\Tasks\Integrations;

use Nvl\Tasks\Contracts\TaskActivityWorklist;

/** Supplies an empty worklist while task Activity recovery is inactive. */
final class EmptyTaskActivityWorklist implements TaskActivityWorklist
{
    /**
     * Return no tenants without resolving Activity types.
     *
     * @return list<string>
     */
    public function activeTenantIds(): array
    {
        return [];
    }
}
