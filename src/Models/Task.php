<?php

declare(strict_types=1);

namespace Nvl\Tasks\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Nvl\Content\Contracts\ContentOwner;
use Nvl\Content\Traits\HasContent;
use Nvl\Media\Contracts\HasMedia;
use Nvl\Media\Enums\MimeType;
use Nvl\Media\Traits\InteractsWithMedia;
use Nvl\Metafields\Traits\HasMetafields;
use Nvl\Tasks\Definitions\Tables\TasksTables;
use Nvl\Tasks\Enums\TaskPriority;
use Nvl\Tasks\Enums\TaskStatus;
use Nvl\Tasks\Support\TasksConfiguration;

/** A tenant-owned task with app-agnostic actors and optional package-owned extensions.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $creator_type
 * @property string|null $creator_id
 * @property string $title
 * @property string|null $description
 * @property TaskStatus $status
 * @property TaskPriority $priority
 * @property CarbonImmutable|null $due_at
 * @property CarbonImmutable|null $completed_at
 * @property array<string, mixed>|null $metadata
 * @property int $revision
 * @property-read int $assignments_count
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Model|null $creator
 * @property-read Collection<int, TaskAssignment> $assignments
 */
final class Task extends Model implements ContentOwner, HasMedia
{
    use HasContent;
    use HasMetafields;
    use HasUuids;
    use InteractsWithMedia;
    use SoftDeletes;

    public const string CONTENT_GROUP = 'details';

    public const string CONTENT_OWNER_TYPE = 'task';

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
        'due_at',
        'metadata',
    ];

    /** Return the configured task table. */
    public function getTable(): string
    {
        return TasksConfiguration::table(TasksTables::Tasks);
    }

    /** Return the configured Tasks database connection. */
    public function getConnectionName(): ?string
    {
        return TasksConfiguration::connection() ?? parent::getConnectionName();
    }

    /** Define private, bounded task attachments owned by Media. */
    public function registerMediaSlots(): void
    {
        $this->addMediaSlot('attachments')
            ->privateExclusive()
            ->onlyKeepLatest(TasksConfiguration::limit('media.maximum_attachments', 10))
            ->maxFileSize(TasksConfiguration::limit('media.maximum_file_bytes', 20 * 1024 * 1024))
            ->acceptsMimeTypes([...MimeType::images(), ...MimeType::documents()]);
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

    /** Return attribute casts aligned with the task schema.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'due_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'metadata' => 'array',
            'revision' => 'integer',
        ];
    }
}
