<?php

declare(strict_types=1);

namespace Nvl\Tasks\Tests\Fixtures\Consumer;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

interface TaskPrincipalResolver
{
    public function resolve(string $identifier): Model&Authenticatable;
}
