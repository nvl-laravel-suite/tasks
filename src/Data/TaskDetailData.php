<?php

declare(strict_types=1);

namespace Nvl\Tasks\Data;

use Nvl\Data\Traits\DataTransform;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskChecklistItem;
use Nvl\Tasks\Models\TaskTimeEntry;
use Nvl\Tasks\Support\TasksConfiguration;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use UnexpectedValueException;

/** A bounded detail view of one authorized task and its package-owned records. */
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class TaskDetailData extends Data
{
    use DataTransform;

    /**
     * @param  list<array<string, mixed>>  $checklist
     * @param  list<string>  $tags
     * @param  list<array<string, mixed>>  $timeEntries
     * @param  list<string>  $childIds
     * @param  list<string>  $blockerIds
     * @param  list<string>  $blockedTaskIds
     */
    public function __construct(
        public readonly TaskData $task,
        #[LiteralTypeScriptType('Array<Record<string, unknown>>')]
        public readonly array $checklist,
        #[LiteralTypeScriptType('string[]')]
        public readonly array $tags,
        #[LiteralTypeScriptType('Array<Record<string, unknown>>')]
        public readonly array $timeEntries,
        public readonly int $loggedSeconds,
        public readonly ?string $parentId,
        #[LiteralTypeScriptType('string[]')]
        public readonly array $childIds,
        #[LiteralTypeScriptType('string[]')]
        public readonly array $blockerIds,
        #[LiteralTypeScriptType('string[]')]
        public readonly array $blockedTaskIds,
    ) {}

    /** Project package-owned records and already-authorized related task IDs.
     *
     * @param  list<string>  $childIds
     * @param  list<string>  $blockerIds
     * @param  list<string>  $blockedTaskIds
     */
    public static function fromModel(
        Task $task,
        ?string $parentId = null,
        array $childIds = [],
        array $blockerIds = [],
        array $blockedTaskIds = [],
    ): self {
        $checklist = array_values($task->checklistItems()->where('tenant_id', $task->tenant_id)
            ->orderBy('position')->limit(TasksConfiguration::limit('detail.maximum_checklist_items', 200))
            ->get()->map(static fn (TaskChecklistItem $item): array => TaskChecklistItemData::fromModel($item)->toArray())
            ->all());
        $tags = self::stringList($task->tags()->where('tenant_id', $task->tenant_id)
            ->orderBy('tag')->pluck('tag'));
        $timeEntries = array_values($task->timeEntries()->where('tenant_id', $task->tenant_id)
            ->orderByDesc('started_at')->limit(TasksConfiguration::limit('detail.maximum_time_entries', 100))
            ->get()->map(static fn (TaskTimeEntry $entry): array => [
                'id' => $entry->id,
                'performerType' => $entry->performer_type,
                'performerId' => $entry->performer_id,
                'startedAt' => $entry->started_at->toISOString(),
                'stoppedAt' => $entry->stopped_at?->toISOString(),
                'durationSeconds' => $entry->duration_seconds,
                'description' => $entry->description,
            ])->all());

        return new self(
            task: TaskData::fromModel($task),
            checklist: $checklist,
            tags: $tags,
            timeEntries: $timeEntries,
            loggedSeconds: (int) $task->timeEntries()->where('tenant_id', $task->tenant_id)->sum('duration_seconds'),
            parentId: $parentId,
            childIds: $childIds,
            blockerIds: $blockerIds,
            blockedTaskIds: $blockedTaskIds,
        );
    }

    /**
     * @param  iterable<mixed>  $values
     * @return list<string>
     */
    private static function stringList(iterable $values): array
    {
        $strings = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                throw new UnexpectedValueException('A task detail identifier must be a string.');
            }

            $strings[] = $value;
        }

        return $strings;
    }
}
