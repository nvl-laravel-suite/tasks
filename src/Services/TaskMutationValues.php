<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Nvl\Tasks\Data\Mutations\CreateTaskData;
use Nvl\Tasks\Data\Mutations\UpdateTaskData;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Support\TaskEnumConfiguration;
use Spatie\LaravelData\Optional;

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
        $status = $this->enumValue('status', $data->status);

        return [
            ...$data->except('metadata')->toModelFiltered(),
            'title' => trim($data->title),
            'status' => $status,
            'priority' => $this->enumValue('priority', $data->priority),
            'type' => $this->enumValue('type', $data->type),
            'category' => $this->enumValue('category', $data->category),
            'importance' => $this->enumValue('importance', $data->importance),
            'metadata' => $data->metadata,
            'completed_at' => $this->completedAt($status),
        ];
    }

    /** Produce a replacement payload that retains omitted fields and honors explicit clears.
     *
     * @return array<string, mixed>
     */
    public function replace(UpdateTaskData $data, Task $current): array
    {
        $this->validate($data);
        $status = $this->enumValue('status', $data->status);
        $patch = $data->except('expectedRevision')->toModelPatch();
        $this->validateFinalSchedule($patch, $current);

        return [
            ...$patch,
            'title' => trim($data->title),
            'status' => $status,
            'priority' => $this->enumValue('priority', $data->priority),
            'type' => $this->replacementEnumValue('type', $data->type, $current->type),
            'category' => $this->replacementEnumValue('category', $data->category, $current->category),
            'importance' => $this->replacementEnumValue('importance', $data->importance, $current->importance),
            'completed_at' => $this->completedAt($status, $current->completed_at),
        ];
    }

    /** Normalize an optional classification using its current value or explicit default. */
    private function replacementEnumValue(string $field, BackedEnum|string|Optional|null $value, BackedEnum $current): string
    {
        return $this->enumValue($field, $value instanceof Optional ? $current : $value);
    }

    /** Normalize one configured enum case into its persisted string value. */
    private function enumValue(string $field, BackedEnum|string|null $value): string
    {
        if ($value === null) {
            return TaskEnumConfiguration::defaultValue($field);
        }

        $candidate = $value instanceof BackedEnum ? $value->value : $value;
        $enum = TaskEnumConfiguration::enumClass($field);

        if (! is_string($candidate) || $enum::tryFrom($candidate) === null) {
            throw ValidationException::withMessages([$field => ["The selected {$field} is invalid."]]);
        }

        return $candidate;
    }

    /** Validate a DTO even when a PHP consumer constructs it directly. */
    private function validate(CreateTaskData|UpdateTaskData $data): void
    {
        $values = $data->toArray();
        $values['title'] = trim($data->title);
        Validator::make($values, $data::rules())->validate();

        if ($data->metadata instanceof Optional) {
            return;
        }

        $encoded = json_encode($data->metadata, JSON_THROW_ON_ERROR);

        if (strlen($encoded) > 65_536) {
            throw ValidationException::withMessages([
                'metadata' => ['Task metadata must be at most 64 KiB.'],
            ]);
        }
    }

    /** Validate the persisted schedule after applying an optional update patch.
     *
     * @param  array<string, mixed>  $patch
     */
    private function validateFinalSchedule(array $patch, Task $current): void
    {
        $targetAt = array_key_exists('target_at', $patch)
            ? $patch['target_at']
            : $current->target_at?->toISOString();
        $dueAt = array_key_exists('due_at', $patch)
            ? $patch['due_at']
            : $current->due_at?->toISOString();

        Validator::make([
            'targetAt' => $targetAt,
            'dueAt' => $dueAt,
        ], [
            'targetAt' => ['nullable', 'date'],
            'dueAt' => ['nullable', 'date', 'after_or_equal:targetAt'],
        ])->validate();
    }

    /** Keep the original completion instant until a task is reopened. */
    private function completedAt(string $status, ?CarbonImmutable $existing = null): ?CarbonImmutable
    {
        return $status === TaskEnumConfiguration::completedStatus()
            ? ($existing ?? CarbonImmutable::now())
            : null;
    }
}
