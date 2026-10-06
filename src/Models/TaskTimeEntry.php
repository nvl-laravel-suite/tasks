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
use Nvl\Tasks\Database\Factories\TaskTimeEntryFactory;
use Nvl\Tasks\Definitions\Tables\TasksTables;
use Nvl\Tasks\Support\TasksConfiguration;

/** A manual interval or running timer belonging to a canonical task and performer.
 *
 * @property string $id
 * @property string $task_id
 * @property string|null $tenant_id
 * @property string $performer_type
 * @property string $performer_id
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $stopped_at
 * @property int|null $duration_seconds
 * @property string|null $description
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Task $task
 * @property-read Model|null $performer
 *
 * @api
 */
final class TaskTimeEntry extends Model
{
    /** @use HasFactory<TaskTimeEntryFactory> */
    use HasFactory;

    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'task_id',
        'performer_type',
        'performer_id',
        'started_at',
        'stopped_at',
        'duration_seconds',
        'description',
    ];

    /** Return the configured time-entry table. */
    public function getTable(): string
    {
        return TasksConfiguration::table(TasksTables::get(TasksTables::TimeEntries));
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

    /** Resolve the host-owned performer.
     *
     * @return MorphTo<Model, $this>
     */
    public function performer(): MorphTo
    {
        return $this->morphTo('performer', 'performer_type', 'performer_id');
    }

    /** Return casts aligned with the time-entry schema.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'stopped_at' => 'immutable_datetime',
            'duration_seconds' => 'integer',
        ];
    }

    /**
     * Return the package's runtime fixture factory.
     *
     * @internal
     */
    protected static function newFactory(): TaskTimeEntryFactory
    {
        return TaskTimeEntryFactory::new();
    }
}
