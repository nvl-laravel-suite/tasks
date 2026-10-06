<?php

declare(strict_types=1);

namespace Nvl\Tasks\Contracts;

/** Enumerates reviewed tenant identifiers for task activity recovery. */
interface TaskActivityWorklist
{
    /**
     * Return tenant identifiers selected by the active Activity adapter.
     *
     * @return list<string>
     */
    public function activeTenantIds(): array;
}
