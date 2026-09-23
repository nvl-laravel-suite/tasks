<?php

declare(strict_types=1);

namespace Nvl\Tasks\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Nvl\Tasks\Models\Task;

/** Bounded management projection; never serializes relations or Eloquent internals. */
final class TaskResource extends JsonResource
{
    /** Return an authorized task row for app tables and detail views.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Task $task */
        $task = $this->resource;

        return [
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'dueAt' => $task->due_at?->toISOString(),
            'completedAt' => $task->completed_at?->toISOString(),
            'metadata' => $task->metadata ?? [],
            'revision' => $task->revision,
            'creatorType' => $task->creator_type,
            'creatorId' => $task->creator_id,
            'assigneesCount' => $this->whenCounted('assignments'),
            'createdAt' => $task->created_at->toISOString(),
            'updatedAt' => $task->updated_at->toISOString(),
        ];
    }
}
