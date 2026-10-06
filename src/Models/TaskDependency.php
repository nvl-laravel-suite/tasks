<?php

declare(strict_types=1);

namespace Nvl\Tasks\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvl\Support\Config\PackageStorage;
use Nvl\Tasks\Definitions\Tables\TasksTables;
use Nvl\Tasks\Support\TasksConfiguration;

/** One blocker edge from a task to the task it waits for.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $task_id
 * @property string $blocker_task_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Task $task
 * @property-read Task $blocker
 *
 * @api
 */
final class TaskDependency extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'task_id',
        'blocker_task_id',
    ];

    /** Return the configured task dependency table. */
    public function getTable(): string
    {
        return TasksConfiguration::table(TasksTables::get(TasksTables::Dependencies));
    }

    /** Return the configured Tasks database connection. */
    public function getConnectionName(): ?string
    {
        return PackageStorage::connectionName($this->connection ?? PackageStorage::connection('tasks') ?? parent::getConnectionName());
    }

    /** Return the blocked task.
     *
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    /** Return the task that blocks this one.
     *
     * @return BelongsTo<Task, $this>
     */
    public function blocker(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'blocker_task_id');
    }
}
