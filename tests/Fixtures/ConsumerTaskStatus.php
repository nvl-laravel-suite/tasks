<?php

declare(strict_types=1);

namespace Nvl\Tasks\Tests\Fixtures;

enum ConsumerTaskStatus: string
{
    case InQueue = 'in_queue';
    case Done = 'done';
}
