<?php

declare(strict_types=1);

namespace Nvl\Tasks\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Nvl\Support\Config\PackageStorage;
use Nvl\Tasks\Database\Factories\TaskChecklistItemFactory;
use Nvl\Tasks\Definitions\Tables\TasksTables;
use Nvl\Tasks\Support\TasksConfiguration;

/** One ordered, independently completable item in a task checklist.
 *
 * @property string $id
 * @property string $task_id
 * @property string|null $tenant_id
 * @property string $title
 * @property int $position
 * @property CarbonImmutable|null $completed_at
 * @property string|null $completed_by_type
 * @property string|null $completed_by_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Task $task
 * @property-read Model|null $completedBy
 *
 * @api
 */
final class TaskChecklistItem extends Model
{
    /** @use HasFactory<TaskChecklistItemFactory> */
    use HasFactory;

    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'title',
        'position',
    ];

    /** Return the configured checklist table. */
    public function getTable(): string
    {
        return TasksConfiguration::table(TasksTables::get(TasksTables::ChecklistItems));
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

    /** Return the host principal who completed this item.
     *
     * @return MorphTo<Model, $this>
     */
    public function completedBy(): MorphTo
    {
        return $this->morphTo('completedBy', 'completed_by_type', 'completed_by_id');
    }

    /** Cast checklist persistence values.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Return the package's runtime fixture factory.
     *
     * @internal
     */
    protected static function newFactory(): TaskChecklistItemFactory
    {
        return TaskChecklistItemFactory::new();
    }
}
