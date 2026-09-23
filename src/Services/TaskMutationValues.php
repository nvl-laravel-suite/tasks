<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Nvl\Tasks\Data\Mutations\CreateTaskData;
use Nvl\Tasks\Data\Mutations\UpdateTaskData;
use Nvl\Tasks\Enums\TaskStatus;

/** Validates and maps task DTOs without rewriting application metadata keys. */
final class TaskMutationValues
{
    /** Produce a create payload without caller-controlled ownership fields.
     *
     * @return array<string, mixed>
     */
    public function create(CreateTaskData $data): array
    {
        $this->validate($data);

        return [
            ...$data->except('metadata')->toModelFiltered(),
            'title' => trim($data->title),
            'metadata' => $data->metadata,
            'completed_at' => $this->completedAt($data->status),
        ];
    }

    /** Produce a complete replacement payload preserving explicit clears.
     *
     * @return array<string, mixed>
     */
    public function replace(UpdateTaskData $data, ?CarbonImmutable $completedAt): array
    {
        $this->validate($data);

        return [
            ...$data->except('expectedRevision', 'metadata')->toModelPatch(),
            'title' => trim($data->title),
            'metadata' => $data->metadata,
            'completed_at' => $this->completedAt($data->status, $completedAt),
        ];
    }

    /** Validate a DTO even when a PHP consumer constructs it directly. */
    private function validate(CreateTaskData|UpdateTaskData $data): void
    {
        $values = $data->toArray();
        $values['title'] = trim($data->title);
        Validator::make($values, $data::rules())->validate();

        $encoded = json_encode($data->metadata, JSON_THROW_ON_ERROR);

        if (strlen($encoded) > 65_536) {
            throw ValidationException::withMessages([
                'metadata' => ['Task metadata must be at most 64 KiB.'],
            ]);
        }
    }

    /** Keep the original completion instant until a task is reopened. */
    private function completedAt(TaskStatus $status, ?CarbonImmutable $existing = null): ?CarbonImmutable
    {
        return $status === TaskStatus::Completed
            ? ($existing ?? CarbonImmutable::now())
            : null;
    }
}
