<?php

declare(strict_types=1);

namespace Nvl\Tasks\Data;

use Nvl\Data\Traits\DataTransform;
use Nvl\Tasks\Models\TaskAssignment;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Stable assignment identity without a host principal model. */
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class TaskAssignmentData extends Data
{
    use DataTransform;

    /** Create one assignment projection. */
    public function __construct(
        public readonly string $id,
        public readonly string $taskId,
        public readonly string $assigneeType,
        public readonly string $assigneeId,
        public readonly string $createdAt,
    ) {}

    /** Project one persisted assignment. */
    public static function fromModel(TaskAssignment $assignment): self
    {
        return new self(
            id: $assignment->id,
            taskId: $assignment->task_id,
            assigneeType: $assignment->assignee_type,
            assigneeId: $assignment->assignee_id,
            createdAt: $assignment->created_at->toISOString() ?? '',
        );
    }
}
