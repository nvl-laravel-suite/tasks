<?php

declare(strict_types=1);

namespace Nvl\Tasks\Tests\Fixtures;

enum ConsumerTaskType: string
{
    case General = 'general';
    case Meeting = 'meeting';
}
