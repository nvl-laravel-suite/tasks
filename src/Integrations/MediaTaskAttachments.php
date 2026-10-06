<?php

declare(strict_types=1);

namespace Nvl\Tasks\Integrations;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Nvl\Media\Contracts\AttachMediaContract;
use Nvl\Media\Contracts\DetachMediaContract;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Enums\MimeType;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Services\MediaMutationLock;
use Nvl\Media\Services\MediaStagingPolicy;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Tasks\Contracts\TaskAttachments;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Support\TasksConfiguration;

/** Uses Media's public mutation contracts for authorized private task attachments. */
final readonly class MediaTaskAttachments implements TaskAttachments
{
    /** Create the optional attachment policy and lifecycle adapter. */
    public function __construct(
        private TaskAuthorization $tasks,
        private TenantBoundary $boundary,
        private MediaAuthorization $authorization,
        private MediaStagingPolicy $policy,
        private MediaMutationLock $locks,
        private AttachMediaContract $attachMedia,
        private DetachMediaContract $detachMedia,
    ) {}

    /** Associate an existing private asset while retaining the latest bounded task attachments. */
    public function attach(Task $task, string $mediaId, TaskActorData $actor): string
    {
        $this->boundary->assertRecord($task, Task::TENANT_RESOURCE);
        $this->tasks->authorize(TaskAbility::Update, $actor, $task);

        return $this->locks->executeForOwnerCollection($task, 'attachments', fn (): string => $this->locks->execute($mediaId, function () use ($task, $mediaId, $actor): string {
            $media = Media::query()->findOrFail($mediaId);
            $this->authorize(MediaAbility::Associate, $media, $task, $actor);
            $this->policy->assertFitsSlot($media, $this->slot());
            $foreignAssociation = MediaAssociation::query()->where('media_id', $mediaId)
                ->where(static function (Builder $query) use ($task): void {
                    $query->where('associable_type', '!=', $task->getMorphClass())
                        ->orWhere('associable_id', '!=', $task->id)
                        ->orWhere('collection', '!=', 'attachments');
                })->exists();

            if ($foreignAssociation) {
                throw new MediaUploadException('Private task attachments cannot be shared with another owner or collection.');
            }

            $association = $this->attachMedia->execute($media, $task, 'attachments', dispatchVariations: false);
            $excess = MediaAssociation::query()->forModel($task)->forCollection('attachments')
                ->orderByDesc('created_at')->orderByDesc('id')
                ->offset(TasksConfiguration::limit('media.maximum_attachments', 10))->limit(1000)->get();

            foreach ($excess as $previous) {
                $this->detachMedia->execute($previous->media_id, $task, 'attachments');
            }

            return $association->id;
        }));
    }

    /** Remove one authorized association through Media's lifecycle boundary. */
    public function detach(Task $task, string $mediaId, TaskActorData $actor): int
    {
        $this->boundary->assertRecord($task, Task::TENANT_RESOURCE);
        $this->tasks->authorize(TaskAbility::Update, $actor, $task);
        $media = Media::query()->findOrFail($mediaId);
        $this->authorize(MediaAbility::Associate, $media, $task, $actor);

        return $this->locks->executeForOwnerCollection($task, 'attachments',
            fn (): int => $this->detachMedia->execute($media, $task, 'attachments'));
    }

    /**
     * Return authorized asset identifiers without exposing optional Media DTOs.
     *
     * @return list<string>
     */
    public function ids(Task $task, TaskActorData $actor): array
    {
        $this->boundary->assertRecord($task, Task::TENANT_RESOURCE);
        $this->tasks->authorize(TaskAbility::View, $actor, $task);
        $ids = [];

        foreach (MediaAssociation::query()->forModel($task)->forCollection('attachments')->with('media')->ordered()->get() as $association) {
            if ($association->media instanceof Media) {
                $this->authorize(MediaAbility::View, $association->media, $task, $actor);
                $ids[] = $association->media_id;
            }
        }

        return $ids;
    }

    /** Return the private file policy owned by the Tasks attachment integration. */
    public function slot(): MediaSlot
    {
        return (new MediaSlot('attachments'))->privateExclusive()
            ->onlyKeepLatest(TasksConfiguration::limit('media.maximum_attachments', 10))
            ->maxFileSize(TasksConfiguration::limit('media.maximum_file_bytes', 20 * 1024 * 1024))
            ->acceptsMimeTypes([...MimeType::images(), ...MimeType::documents()]);
    }

    private function authorize(MediaAbility $ability, Media $media, Task $task, TaskActorData $actor): void
    {
        if (! $this->authorization->allows(new MediaActorData($actor->type, $actor->id, $actor->system), $ability, $media, $task)) {
            throw new AuthorizationException('The actor is not authorized to manage this task attachment.');
        }
    }
}
