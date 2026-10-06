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

/** One normalized label attached to a canonical task.
 *
 * @property string $id
 * @property string $task_id
 * @property string|null $tenant_id
 * @property string $tag
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Task $task
 */
final class TaskTag extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['task_id', 'tag'];

    /** Return the configured task-tag table. */
    public function getTable(): string
    {
        return TasksConfiguration::table(TasksTables::get(TasksTables::Tags));
    }

    /** Return the configured Tasks database connection. */
    public function getConnectionName(): ?string
    {
        return PackageStorage::connectionName($this->connection ?? PackageStorage::connection('tasks') ?? parent::getConnectionName());
    }

    /** Return the canonical parent task.
     *
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }
}
