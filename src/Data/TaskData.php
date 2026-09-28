<?php

declare(strict_types=1);

namespace Nvl\Tasks\Data;

use Nvl\Data\Traits\DataTransform;
use Nvl\Tasks\Models\Task;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Bounded task projection for authorized management consumers. */
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class TaskData extends Data
{
    use DataTransform;

    /** Create one task projection.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly ?string $description,
        public readonly string $status,
        public readonly string $priority,
        public readonly string $type,
        public readonly string $category,
        public readonly string $importance,
        public readonly ?string $dueAt,
        public readonly ?string $targetAt,
        public readonly ?int $estimatedSeconds,
        public readonly ?string $completedAt,
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $metadata,
        public readonly int $revision,
        public readonly ?string $creatorType,
        public readonly ?string $creatorId,
        public readonly int|Optional $assigneesCount,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    /** Project a task without loading relations or leaking Eloquent internals. */
    public static function fromModel(Task $task): self
    {
        return new self(
            id: $task->id,
            title: $task->title,
            description: $task->description,
            status: (string) $task->status->value,
            priority: (string) $task->priority->value,
            type: (string) $task->type->value,
            category: (string) $task->category->value,
            importance: (string) $task->importance->value,
            dueAt: $task->due_at?->toISOString(),
            targetAt: $task->target_at?->toISOString(),
            estimatedSeconds: $task->estimated_seconds,
            completedAt: $task->completed_at?->toISOString(),
            metadata: $task->metadata ?? [],
            revision: $task->revision,
            creatorType: $task->creator_type,
            creatorId: $task->creator_id,
            assigneesCount: array_key_exists('assignments_count', $task->getAttributes())
                ? $task->assignments_count
                : Optional::create(),
            createdAt: $task->created_at->toISOString() ?? '',
            updatedAt: $task->updated_at->toISOString() ?? '',
        );
    }
}
