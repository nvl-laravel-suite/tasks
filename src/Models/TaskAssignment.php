<?php

declare(strict_types=1);

namespace Nvl\Tasks\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Nvl\Tasks\Definitions\Tables\TasksTables;
use Nvl\Tasks\Support\TasksConfiguration;

/** One durable assignment of a task to a host application's principal.
 *
 * @property string $id
 * @property string $task_id
 * @property string|null $tenant_id
 * @property string $assignee_type
 * @property string $assignee_id
 * @property string|null $assigned_by_type
 * @property string|null $assigned_by_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Task $task
 * @property-read Model|null $assignee
 * @property-read Model|null $assignedBy
 */
final class TaskAssignment extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'task_id',
        'assignee_type',
        'assignee_id',
        'assigned_by_type',
        'assigned_by_id',
    ];

    /** Return the configured task-assignment table. */
    public function getTable(): string
    {
        return TasksConfiguration::table(TasksTables::Assignments);
    }

    /** Return the configured Tasks database connection. */
    public function getConnectionName(): ?string
    {
        return TasksConfiguration::connection() ?? parent::getConnectionName();
    }

    /** Return the assignment's canonical task.
     *
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    /** Resolve the host-owned assignee.
     *
     * @return MorphTo<Model, $this>
     */
    public function assignee(): MorphTo
    {
        return $this->morphTo('assignee', 'assignee_type', 'assignee_id');
    }

    /** Resolve the host-owned principal who made this assignment.
     *
     * @return MorphTo<Model, $this>
     */
    public function assignedBy(): MorphTo
    {
        return $this->morphTo('assignedBy', 'assigned_by_type', 'assigned_by_id');
    }
}
