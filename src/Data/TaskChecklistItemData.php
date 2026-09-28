<?php

declare(strict_types=1);

namespace Nvl\Tasks\Data;

use Nvl\Data\Traits\DataTransform;
use Nvl\Tasks\Models\TaskChecklistItem;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Stable checklist projection without host principal models. */
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class TaskChecklistItemData extends Data
{
    use DataTransform;

    /** Create one checklist item projection. */
    public function __construct(
        public readonly string $id,
        public readonly string $taskId,
        public readonly string $title,
        public readonly int $position,
        public readonly ?string $completedAt,
        public readonly ?string $completedByType,
        public readonly ?string $completedById,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    /** Project one persisted checklist item. */
    public static function fromModel(TaskChecklistItem $item): self
    {
        return new self(
            id: $item->id,
            taskId: $item->task_id,
            title: $item->title,
            position: $item->position,
            completedAt: $item->completed_at?->toISOString(),
            completedByType: $item->completed_by_type,
            completedById: $item->completed_by_id,
            createdAt: $item->created_at->toISOString() ?? '',
            updatedAt: $item->updated_at->toISOString() ?? '',
        );
    }
}
