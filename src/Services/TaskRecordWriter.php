<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;
use Nvl\Tasks\Models\TaskAssignment;
use Nvl\Tasks\Models\TaskChecklistItem;
use Nvl\Tasks\Models\TaskDependency;
use Nvl\Tasks\Models\TaskRelationship;
use Nvl\Tasks\Models\TaskTag;
use Nvl\Tasks\Models\TaskTimeEntry;

/**
 * Persists only package-owned Task children through their canonical parent assignment.
 *
 * @internal
 */
final readonly class TaskRecordWriter
{
    /** Retain the neutral writer without retaining a model or execution scope. */
    public function __construct(private OwnershipWriter $writer) {}

    /** Create one assignment under the current canonical task scope.
     * @param  array<string, mixed>  $attributes
     */
    public function assignment(array $attributes): TaskAssignment
    {
        return $this->persist(TaskAssignment::class, $attributes);
    }

    /** Create one checklistItem under the current canonical task scope.
     * @param  array<string, mixed>  $attributes
     */
    public function checklistItem(array $attributes): TaskChecklistItem
    {
        return $this->persist(TaskChecklistItem::class, $attributes);
    }

    /** Create one timeEntry under the current canonical task scope.
     * @param  array<string, mixed>  $attributes
     */
    public function timeEntry(array $attributes): TaskTimeEntry
    {
        return $this->persist(TaskTimeEntry::class, $attributes);
    }

    /** Create one tag under the current canonical task scope.
     * @param  array<string, mixed>  $attributes
     */
    public function tag(array $attributes): TaskTag
    {
        return $this->persist(TaskTag::class, $attributes);
    }

    /** Create one relationship under the current canonical task scope.
     * @param  array<string, mixed>  $attributes
     */
    public function relationship(array $attributes): TaskRelationship
    {
        return $this->persist(TaskRelationship::class, $attributes);
    }

    /** Create one dependency under the current canonical task scope.
     * @param  array<string, mixed>  $attributes
     */
    public function dependency(array $attributes): TaskDependency
    {
        return $this->persist(TaskDependency::class, $attributes);
    }

    /**
     * @template T of TaskAssignment|TaskChecklistItem|TaskTimeEntry|TaskTag|TaskRelationship|TaskDependency
     *
     * @param  class-string<T>  $class
     * @param  array<string, mixed>  $attributes
     * @return T
     */
    private function persist(string $class, array $attributes): Model
    {
        return $this->writer->create($class, static function (array $ownership) use ($class, $attributes): Model {
            $record = new $class;
            $record->forceFill([...$attributes, ...$ownership])->save();

            return $record;
        });
    }
}
