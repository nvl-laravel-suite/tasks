<?php

declare(strict_types=1);

namespace Nvl\Tasks\Tests\Fixtures;

use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Tasks\Models\Task;

/** Consumer extension choosing one native primary owner through a concrete pivot. */
final class PivotTask extends Task
{
    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::pivot(Company::class, 'consumer_task_owners', 'task_id', 'company_id');
    }
}
