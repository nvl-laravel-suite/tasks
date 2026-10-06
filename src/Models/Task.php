<?php

declare(strict_types=1);

namespace Nvl\Tasks\Models;

use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Nvl\Support\Config\PackageStorage;
use Nvl\Tasks\Definitions\Tables\TasksTables;
use Nvl\Tasks\Support\TaskEnumConfiguration;
use Nvl\Tasks\Support\TasksConfiguration;

/** A tenant-owned task with app-agnostic actors and optional package-owned extensions.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $creator_type
 * @property string|null $creator_id
 * @property string $title
 * @property string|null $description
 * @property BackedEnum $status
 * @property BackedEnum $priority
 * @property BackedEnum $type
 * @property BackedEnum $category
 * @property BackedEnum $importance
 * @property CarbonImmutable|null $due_at
 * @property CarbonImmutable|null $target_at
 * @property int|null $estimated_seconds
 * @property CarbonImmutable|null $completed_at
 * @property array<string, mixed>|null $metadata
 * @property int $revision
 * @property-read int $assignments_count
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Model|null $creator
 * @property-read Collection<int, TaskAssignment> $assignments
 * @property-read Collection<int, TaskChecklistItem> $checklistItems
 * @property-read Collection<int, TaskTimeEntry> $timeEntries
 * @property-read Collection<int, TaskTag> $tags
 */
final class Task extends Model
{
    use HasUuids;
    use SoftDeletes;

    public const string TENANT_RESOURCE = 'tasks.tasks';

    /** @var array<string, int|string> */
    protected $attributes = [
        'status' => 'open',
        'priority' => 'normal',
        'revision' => 1,
    ];

    /** @var list<string> */
    protected $fillable = [
        'title',
        'description',
        'status',
        'priority',
        'type',
        'category',
        'importance',
        'due_at',
        'target_at',
        'estimated_seconds',
        'metadata',
    ];

    /** Return the configured task table. */
    public function getTable(): string
    {
        return TasksConfiguration::table(TasksTables::get(TasksTables::Tasks));
    }

    /** Return the configured Tasks database connection. */
    public function getConnectionName(): ?string
    {
        return PackageStorage::connectionName($this->connection ?? PackageStorage::connection('tasks') ?? parent::getConnectionName());
    }

    /** Return the host-owned creator without assuming an Auth package model.
     *
     * @return MorphTo<Model, $this>
     */
    public function creator(): MorphTo
    {
        return $this->morphTo('creator', 'creator_type', 'creator_id');
    }

    /** Return the task's many assignee records.
     *
     * @return HasMany<TaskAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(TaskAssignment::class, 'task_id');
    }

    /** @return HasMany<TaskChecklistItem, $this> */
    public function checklistItems(): HasMany
    {
        return $this->hasMany(TaskChecklistItem::class, 'task_id');
    }

    /** @return HasMany<TaskTimeEntry, $this> */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TaskTimeEntry::class, 'task_id');
    }

    /** @return HasMany<TaskTag, $this> */
    public function tags(): HasMany
    {
        return $this->hasMany(TaskTag::class, 'task_id');
    }

    /** @return HasMany<TaskRelationship, $this> */
    public function parentLink(): HasMany
    {
        return $this->hasMany(TaskRelationship::class, 'child_task_id');
    }

    /** @return HasMany<TaskRelationship, $this> */
    public function childLinks(): HasMany
    {
        return $this->hasMany(TaskRelationship::class, 'parent_task_id');
    }

    /** @return HasMany<TaskDependency, $this> */
    public function blockerLinks(): HasMany
    {
        return $this->hasMany(TaskDependency::class, 'task_id');
    }

    /** @return HasMany<TaskDependency, $this> */
    public function blockedTaskLinks(): HasMany
    {
        return $this->hasMany(TaskDependency::class, 'blocker_task_id');
    }

    /** Return attribute casts aligned with the task schema.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TaskEnumConfiguration::enumClass('status'),
            'priority' => TaskEnumConfiguration::enumClass('priority'),
            'type' => TaskEnumConfiguration::enumClass('type'),
            'category' => TaskEnumConfiguration::enumClass('category'),
            'importance' => TaskEnumConfiguration::enumClass('importance'),
            'due_at' => 'immutable_datetime',
            'target_at' => 'immutable_datetime',
            'estimated_seconds' => 'integer',
            'completed_at' => 'immutable_datetime',
            'metadata' => 'array',
            'revision' => 'integer',
        ];
    }
}
