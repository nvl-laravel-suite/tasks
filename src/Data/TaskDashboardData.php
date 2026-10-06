<?php

declare(strict_types=1);

namespace Nvl\Tasks\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Bounded task dashboard summary for an authorized caller.
 *
 * @api
 */
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class TaskDashboardData extends Data
{
    use DataTransform;

    /** Create the task dashboard summary. */
    public function __construct(
        public readonly int $total,
        public readonly int $open,
        public readonly int $inProgress,
        public readonly int $blocked,
        public readonly int $completed,
        public readonly int $overdue,
        public readonly int $dueSoon,
        public readonly int $dueSoonDays,
        public readonly int $unassigned,
        public readonly int $estimatedSeconds,
        public readonly int $loggedSeconds,
    ) {}
}
