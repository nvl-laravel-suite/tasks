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

/** One child-to-parent edge in the task hierarchy.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $child_task_id
 * @property string $parent_task_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Task $child
 * @property-read Task $parent
 */
final class TaskRelationship extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'child_task_id',
        'parent_task_id',
    ];

    /** Return the configured task hierarchy table. */
    public function getTable(): string
    {
        return TasksConfiguration::table(TasksTables::get(TasksTables::Relationships));
    }

    /** Return the configured Tasks database connection. */
    public function getConnectionName(): ?string
    {
        return PackageStorage::connectionName($this->connection ?? PackageStorage::connection('tasks') ?? parent::getConnectionName());
    }

    /** Return the child task.
     *
     * @return BelongsTo<Task, $this>
     */
    public function child(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'child_task_id');
    }

    /** Return the parent task.
     *
     * @return BelongsTo<Task, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_task_id');
    }
}
