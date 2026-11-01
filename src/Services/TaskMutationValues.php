<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Support\TaskEnumConfiguration;
use Throwable;

/**
 * Validates native task edits and preserves omitted fields.
 *
 * @internal
 */
final class TaskMutationValues
{
    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function create(array $attributes): array
    {
        return $this->normalize($attributes, null);
    }

    /** @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function replace(array $changes, Task $current): array
    {
        return $this->normalize($changes, $current);
    }

    /** Reject unknown fields, including caller-authored ownership and attribution.
     * @param  array<string, mixed>  $values
     * @param  list<string>  $allowed
     */
    public static function keys(array $values, array $allowed): void
    {
        foreach (array_keys($values) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw ValidationException::withMessages([(string) $key => 'This field is not writable.']);
            }
        }
    }

    /** Parse a real instant, reject impossible dates, and store its UTC representation. */
    public static function date(mixed $value, string $field): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }
        if (! is_string($value) || trim($value) === '') {
            throw ValidationException::withMessages([$field => 'Expected a date string or DateTimeInterface.']);
        }
        try {
            $date = new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                throw new \InvalidArgumentException('Invalid date.');
            }

            return CarbonImmutable::instance($date)->utc();
        } catch (Throwable) {
            throw ValidationException::withMessages([$field => 'The date is invalid.']);
        }
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function normalize(array $values, ?Task $current): array
    {
        self::keys($values, ['title', 'description', 'status', 'priority', 'type', 'category', 'importance', 'due_at', 'target_at', 'estimated_seconds', 'metadata']);
        if ($current === null && ! array_key_exists('title', $values)) {
            throw ValidationException::withMessages(['title' => 'A title is required.']);
        }
        foreach (['title', 'description'] as $field) {
            if (! array_key_exists($field, $values)) {
                continue;
            }
            if (! is_string($values[$field]) && ! ($field === 'description' && $values[$field] === null)) {
                throw ValidationException::withMessages([$field => 'Expected a string.']);
            }
        }
        if (isset($values['title']) && is_string($values['title'])) {
            $values['title'] = trim($values['title']);
        }
        if (array_key_exists('metadata', $values)) {
            if (! is_array($values['metadata']) || ($values['metadata'] !== [] && array_is_list($values['metadata']))) {
                throw ValidationException::withMessages(['metadata' => 'Expected an object.']);
            }
            try {
                $encoded = json_encode($values['metadata'], JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                throw ValidationException::withMessages(['metadata' => 'Expected JSON-compatible metadata.']);
            }
            if (strlen($encoded) > 65536) {
                throw ValidationException::withMessages(['metadata' => 'Task metadata must be at most 64 KiB.']);
            }
        }
        if (isset($values['estimated_seconds']) && ! is_int($values['estimated_seconds'])) {
            throw ValidationException::withMessages(['estimated_seconds' => 'Expected an integer.']);
        }
        Validator::make($values, [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'metadata' => ['sometimes', 'array', 'max:64'],
            'estimated_seconds' => ['nullable', 'integer', 'min:1', 'max:31536000'],
        ])->validate();
        foreach (['status', 'priority', 'type', 'category', 'importance'] as $field) {
            if ($current !== null && ! array_key_exists($field, $values)) {
                continue;
            }
            $value = $values[$field] ?? null;
            $candidate = $value instanceof BackedEnum ? $value->value : $value;
            $enum = TaskEnumConfiguration::enumClass($field);
            if ($candidate === null) {
                $candidate = TaskEnumConfiguration::defaultValue($field);
            }
            if (! is_string($candidate) || $enum::tryFrom($candidate) === null) {
                throw ValidationException::withMessages([$field => 'The selected classification is invalid.']);
            }
            $values[$field] = $candidate;
        }
        foreach (['target_at', 'due_at'] as $field) {
            if (array_key_exists($field, $values)) {
                $values[$field] = self::date($values[$field], $field);
                $year = $values[$field]?->year;
                if ($year !== null && ($year < 1000 || $year > 9999)) {
                    throw ValidationException::withMessages([$field => 'Calendar dates must fall between years 1000 and 9999 in UTC.']);
                }
            }
        }
        $target = array_key_exists('target_at', $values) ? $values['target_at'] : $current?->target_at;
        $due = array_key_exists('due_at', $values) ? $values['due_at'] : $current?->due_at;
        if ($target !== null && $due !== null && $due < $target) {
            throw ValidationException::withMessages(['due_at' => 'The due date precedes the target date.']);
        }
        $status = $values['status'] ?? $current?->status->value;
        $values['completed_at'] = $status === TaskEnumConfiguration::completedStatus() ? ($current->completed_at ?? CarbonImmutable::now('UTC')) : null;

        return $values;
    }
}
