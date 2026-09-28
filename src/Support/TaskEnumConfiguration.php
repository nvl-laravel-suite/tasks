<?php

declare(strict_types=1);

namespace Nvl\Tasks\Support;

use BackedEnum;
use InvalidArgumentException;
use Nvl\Tasks\Enums\TaskCategory;
use Nvl\Tasks\Enums\TaskImportance;
use Nvl\Tasks\Enums\TaskPriority;
use Nvl\Tasks\Enums\TaskStatus;
use Nvl\Tasks\Enums\TaskType;
use ReflectionEnum;

/** Resolves replaceable string-backed task enums and their required defaults. */
final class TaskEnumConfiguration
{
    /** @var array<string, class-string<BackedEnum>> */
    private const array FALLBACKS = [
        'status' => TaskStatus::class,
        'priority' => TaskPriority::class,
        'type' => TaskType::class,
        'category' => TaskCategory::class,
        'importance' => TaskImportance::class,
    ];

    /** Return the configured string-backed enum for one task field.
     *
     * @return class-string<BackedEnum>
     */
    public static function enumClass(string $field): string
    {
        $fallback = self::FALLBACKS[$field] ?? throw new InvalidArgumentException("Unknown task enum field [{$field}].");
        $enum = config("tasks.enums.{$field}", $fallback);

        if (! is_string($enum) || ! enum_exists($enum) || ! is_subclass_of($enum, BackedEnum::class)) {
            throw new InvalidArgumentException("tasks.enums.{$field} must be a string-backed enum class.");
        }

        if ((new ReflectionEnum($enum))->getBackingType()?->getName() !== 'string') {
            throw new InvalidArgumentException("tasks.enums.{$field} must be a string-backed enum class.");
        }

        return $enum;
    }

    /** Resolve and validate the default persisted value for one task field. */
    public static function defaultValue(string $field): string
    {
        $default = config("tasks.defaults.{$field}");
        $enum = self::enumClass($field);

        if (! is_string($default) || $enum::tryFrom($default) === null) {
            throw new InvalidArgumentException("tasks.defaults.{$field} must name a case in the configured enum.");
        }

        return $default;
    }

    /** Resolve the task status that records completion. */
    public static function completedStatus(): string
    {
        $value = config('tasks.lifecycle.completed_status');
        $enum = self::enumClass('status');

        if (! is_string($value) || $enum::tryFrom($value) === null) {
            throw new InvalidArgumentException('tasks.lifecycle.completed_status must name a case in the configured status enum.');
        }

        return $value;
    }

    private function __construct() {}
}
