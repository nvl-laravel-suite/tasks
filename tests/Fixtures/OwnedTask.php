<?php

declare(strict_types=1);

namespace Nvl\Tasks\Tests\Fixtures;

use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Tasks\Models\Task;

/** Consumer Task extension with a native integer company ownership column. */
final class OwnedTask extends Task
{
    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::column(Company::class, 'company_id');
    }
}
