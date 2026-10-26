<?php

declare(strict_types=1);

namespace Nvl\Tasks\Integrations;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Media\Services\MediaAttachments;
use Nvl\Media\Services\MediaReader;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Support\Enums\SystemActor;
use Nvl\Tasks\Contracts\TaskAttachments;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Services\TaskPrincipalGuard;
use Nvl\Tasks\Services\TaskReadGuard;

/** Connects admitted Tasks to Media-owned private attachment operations. */
final readonly class MediaTaskAttachments implements TaskAttachments
{
    public function __construct(
        private TaskPrincipalGuard $principals,
        private TaskReadGuard $tasks,
        private TaskAuthorization $authorization,
        private MediaAttachments $attachments,
        private MediaReader $media,
    ) {}

    public function slot(): MediaSlot
    {
        $maximum = config('nvl-tasks.media.maximum_attachments', 10);
        $bytes = config('nvl-tasks.media.maximum_file_bytes', 20 * 1024 * 1024);
        if (! is_int($maximum) || $maximum < 1 || $maximum > 1000 || ! is_int($bytes) || $bytes < 1) {
            throw new \InvalidArgumentException('Tasks attachment limits must be bounded positive integers.');
        }

        return (new MediaSlot('attachments'))->privateExclusive()->onlyKeepLatest($maximum)->maxFileSize($bytes);
    }

    public function attach(Task $task, string $mediaId, Authenticatable|SystemActor $actor): string
    {
        $actor = $this->principals->actor($actor);
        $task = $this->tasks->resolve($task, $actor);
        $this->authorization->authorize(TaskAbility::Update, $actor, $task);
        $media = $this->media->get($actor, $mediaId, false);

        return $this->attachments->attach($actor, $media, $task, 'attachments', slot: $this->slot())->id;
    }

    public function detach(Task $task, string $mediaId, Authenticatable|SystemActor $actor): int
    {
        $actor = $this->principals->actor($actor);
        $task = $this->tasks->resolve($task, $actor);
        $this->authorization->authorize(TaskAbility::Update, $actor, $task);
        $media = $this->media->get($actor, $mediaId, false);

        return $this->attachments->detach($actor, $media, $task, 'attachments');
    }

    public function ids(Task $task, Authenticatable|SystemActor $actor): array
    {
        $actor = $this->principals->actor($actor);
        $task = $this->tasks->resolve($task, $actor);
        $slots = $this->media->slots($actor, $task, ['attachments'], $this->slot()->slotSizeLimit ?? 10);

        return array_map(static fn ($media): string => $media->id, $slots['attachments']);
    }
}
