<?php

declare(strict_types=1);

namespace Nvl\Tasks\Tests;

/** Starts with native declarations and historical rows before adoption. */
abstract class OutboxAdoptionTestCase extends OwnerScopeTestCase
{
    protected function activateOwnership(): bool
    {
        return false;
    }
}
