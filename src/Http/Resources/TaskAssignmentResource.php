<?php

declare(strict_types=1);

namespace Nvl\Tasks\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Nvl\Tasks\Models\TaskAssignment;

/** Returns only the stable assignment identity, not a host user model. */
final class TaskAssignmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TaskAssignment $assignment */
        $assignment = $this->resource;

        return [
            'id' => $assignment->id,
            'taskId' => $assignment->task_id,
            'assigneeType' => $assignment->assignee_type,
            'assigneeId' => $assignment->assignee_id,
            'createdAt' => $assignment->created_at->toISOString(),
        ];
    }
}
