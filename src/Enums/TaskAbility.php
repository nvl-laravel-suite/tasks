<?php

declare(strict_types=1);

namespace Nvl\Tasks\Enums;

/** Names the capabilities a host application must authorize. */
enum TaskAbility: string
{
    case Create = 'create';
    case View = 'view';
    case List = 'list';
    case Update = 'update';
    case Assign = 'assign';
    case Delete = 'delete';

    case Restore = 'restore';
}
