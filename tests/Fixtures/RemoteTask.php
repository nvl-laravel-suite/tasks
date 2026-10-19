<?php

declare(strict_types=1);

namespace Nvl\Tasks\Tests\Fixtures;

use Nvl\Tasks\Models\Task;

/** Consumer-owned table and connection replacement retaining the package persistence hooks. */
final class RemoteTask extends Task
{
    protected $connection = 'ownership_remote';

    public function getTable(): string
    {
        return 'consumer_tasks';
    }
}
