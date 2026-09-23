<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Nvl\Content\Actions\CreateContentBlockAction;
use Nvl\Content\Actions\PlaceContentBlockAction;
use Nvl\Content\Actions\PublishContentBlockAction;
use Nvl\Content\Actions\SyncContentDefinitionsAction;
use Nvl\Content\Contracts\ContentOwnerRegistrar;
use Nvl\Content\Data\ContentActorData;
use Nvl\Content\Data\Mutations\CreateContentBlockData;
use Nvl\Content\Data\Mutations\PlaceContentBlockData;
use Nvl\Content\Schema\ContentDefinitionSource;
use Nvl\Content\Services\ContentDefinitionRegistry;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Metafields\Actions\MetafieldDefinitions\CreateMetafieldDefinitionAction;
use Nvl\Metafields\Actions\Metafields\SetMetafieldAction;
use Nvl\Metafields\Contracts\MetafieldAuthorization;
use Nvl\Metafields\Data\CreateMetafieldDefinitionPayload;
use Nvl\Metafields\Enums\MetafieldAbility;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Nvl\Tasks\Actions\AssignTaskAction;
use Nvl\Tasks\Actions\CreateTaskAction;
use Nvl\Tasks\Actions\DeleteTaskAction;
use Nvl\Tasks\Actions\GetTaskAction;
use Nvl\Tasks\Actions\ListTasksAction;
use Nvl\Tasks\Actions\RestoreTaskAction;
use Nvl\Tasks\Actions\UnassignTaskAction;
use Nvl\Tasks\Actions\UpdateTaskAction;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Contracts\TaskQueryScope;
use Nvl\Tasks\Data\Mutations\CreateTaskData;
use Nvl\Tasks\Data\Mutations\UpdateTaskData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Enums\TaskPriority;
use Nvl\Tasks\Enums\TaskStatus;
use Nvl\Tasks\Exceptions\TaskRevisionConflict;
use Nvl\Tasks\Models\Task;

function taskTestUser(string $name): User
{
    $user = new User;
    $user->setTable('users');
    $user->forceFill([
        'name' => $name,
        'email' => strtolower($name).'@example.test',
        'password' => 'unused',
    ])->save();

    return $user;
}

it('denies user mutations until the consuming app binds task authorization', function (): void {
    expect(Route::has('nvl.tasks.management.index'))->toBeFalse();
    $actor = TaskActorData::fromAuthenticatable(taskTestUser('Owner'));

    expect(fn () => app(CreateTaskAction::class)->execute(new CreateTaskData('Review draft'), $actor))
        ->toThrow(AuthorizationException::class);
});

it('registers bounded private attachments and shared content and metafield ownership', function (): void {
    $task = new Task;
    $slot = $task->getMediaSlot('attachments');

    expect($slot)->not->toBeNull()
        ->and($slot?->isPublic)->toBeFalse()
        ->and($slot?->sharingMode)->toBe(MediaSlot::SHARING_EXCLUSIVE)
        ->and($slot?->slotSizeLimit)->toBe(10)
        ->and($slot?->maxFileSize)->toBe(20 * 1024 * 1024)
        ->and($task->contentGroups())->toBe(['details'])
        ->and(app(ContentOwnerRegistrar::class)->registered('task'))->toBe(Task::class)
        ->and(app(MetafieldOwnerRegistry::class)->all()['task']['model'])->toBe(Task::class);
});

it('uses Media for a private task attachment', function (): void {
    Storage::fake('local');
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Review assets'), TaskActorData::system());
    $media = $task->addMediaFromString('Attachment notes')
        ->usingFileName('notes.txt')
        ->withoutVariations()
        ->slot('attachments');

    expect($media->is_public)->toBeFalse()
        ->and($task->getMedia('attachments'))->toHaveCount(1);

    app(DeleteTaskAction::class)->execute($task, TaskActorData::system());
    $restored = app(RestoreTaskAction::class)->execute($task, 1, TaskActorData::system());

    expect($restored->getMedia('attachments'))->toHaveCount(1);
});

it('places rich detail blocks through Content rather than duplicating them on tasks', function (): void {
    config()->set([
        'content.locales.available' => ['en'],
        'content.locales.required_on_publish' => ['en'],
    ]);
    app(ContentDefinitionRegistry::class)->register(new ContentDefinitionSource(
        key: 'tasks.note',
        name: 'Task note',
        description: null,
        category: 'tasks',
        version: 1,
        view: null,
        schema: ['fields' => [[
            'key' => 'body', 'type' => 'text', 'label' => 'Body',
            'localized' => true, 'required' => true,
        ]]],
        allowedScopes: ['global'],
        allowedRegions: ['main'],
    ));
    $actor = ContentActorData::system();
    app(SyncContentDefinitionsAction::class)->execute($actor);
    $block = app(CreateContentBlockAction::class)->execute(new CreateContentBlockData(
        definition: 'tasks.note',
        key: 'review-notes',
        translations: ['en' => ['body' => 'Review every asset.']],
    ), $actor);
    $published = app(PublishContentBlockAction::class)->execute($block, $block->revision, $actor);
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Review assets'), TaskActorData::system());
    app(PlaceContentBlockAction::class)->execute(
        $published, $task, Task::CONTENT_GROUP, new PlaceContentBlockData(key: 'notes'), $actor,
    );

    expect($task->contentPlacements()->count())->toBe(1);

    app(DeleteTaskAction::class)->execute($task, TaskActorData::system());
    $restored = app(RestoreTaskAction::class)->execute($task, 1, TaskActorData::system());

    expect($restored->contentPlacements()->count())->toBe(1);
});

it('stores typed task extensions through Metafields', function (): void {
    app()->instance(MetafieldAuthorization::class, new class implements MetafieldAuthorization
    {
        public function authorizeDefinition(MetafieldAbility $ability, ?MetafieldDefinition $definition = null): void {}

        public function authorizeOwner(MetafieldAbility $ability, ?Model $owner = null, ?MetafieldDefinition $definition = null): void {}
    });
    app(CreateMetafieldDefinitionAction::class)->execute(CreateMetafieldDefinitionPayload::from([
        'namespace' => 'work',
        'key' => 'kind',
        'type' => 'string',
        'translations' => ['en' => ['title' => 'Work kind']],
        'assignment' => ['ownerType' => 'task', 'section' => 'general'],
    ]));
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Review assets'), TaskActorData::system());
    $field = app(SetMetafieldAction::class)->execute($task, 'work.kind', 'review');

    expect($field->getValue())->toBe('review')
        ->and($task->metafields()->count())->toBe(1);
});

it('reports missing host authorization in strict diagnostics', function (): void {
    $this->artisan('nvl:tasks:doctor', ['--strict' => true, '--format' => 'json'])
        ->assertFailed();
});

it('rejects oversized task metadata before persistence', function (): void {
    expect(fn () => app(CreateTaskAction::class)->execute(new CreateTaskData(
        title: 'Oversized',
        metadata: ['payload' => str_repeat('x', 65_536)],
    ), TaskActorData::system()))->toThrow(ValidationException::class);

    expect(Task::query()->count())->toBe(0);
});

it('rejects titles that become empty after normalization', function (): void {
    expect(fn () => app(CreateTaskAction::class)->execute(
        new CreateTaskData('   '),
        TaskActorData::system(),
    ))->toThrow(ValidationException::class);

    expect(Task::query()->count())->toBe(0);
});

it('lets a host authorization adapter scope task table rows to its actor', function (): void {
    app()->bind(TaskAuthorization::class, static fn () => new class implements TaskAuthorization, TaskQueryScope
    {
        public function authorize(TaskAbility $ability, TaskActorData $actor, ?Task $task = null, ?Model $subject = null): void {}

        public function scopeTasks(Builder $query, TaskActorData $actor): void
        {
            $query->where('creator_type', $actor->type)
                ->where('creator_id', (string) $actor->id);
        }
    });

    $owner = TaskActorData::fromAuthenticatable(taskTestUser('Owner'));
    $other = TaskActorData::fromAuthenticatable(taskTestUser('Other'));
    app(CreateTaskAction::class)->execute(new CreateTaskData('Owner task'), $owner);
    app(CreateTaskAction::class)->execute(new CreateTaskData('Other task'), $other);

    $page = app(ListTasksAction::class)->execute($owner);

    expect($page->total())->toBe(1)
        ->and($page->items()[0]->title)->toBe('Owner task');
});

it('creates, updates, assigns and lists tasks without assuming the host user model', function (): void {
    app()->bind(TaskAuthorization::class, static fn () => new class implements TaskAuthorization
    {
        public function authorize(TaskAbility $ability, TaskActorData $actor, ?Task $task = null, ?Model $subject = null): void {}
    });

    $owner = taskTestUser('Owner');
    $assignee = taskTestUser('Assignee');
    $actor = TaskActorData::fromAuthenticatable($owner);
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData(
        title: '  Review draft  ',
        metadata: ['source' => 'editorial'],
    ), $actor);

    expect($task->title)->toBe('Review draft')
        ->and($task->status)->toBe(TaskStatus::Open)
        ->and($task->priority)->toBe(TaskPriority::Normal)
        ->and($task->creator?->getKey())->toBe($owner->getKey());

    $assignment = app(AssignTaskAction::class)->execute($task, $assignee, $actor);
    $again = app(AssignTaskAction::class)->execute($task, $assignee, $actor);

    expect($again->id)->toBe($assignment->id)
        ->and($task->fresh()?->revision)->toBe(2);

    $updated = app(UpdateTaskAction::class)->execute($task, new UpdateTaskData(
        title: 'Approved draft',
        priority: TaskPriority::High,
        status: TaskStatus::Completed,
        expectedRevision: 2,
        metadata: ['source' => 'editorial'],
    ), $actor);

    expect($updated->status)->toBe(TaskStatus::Completed)
        ->and($updated->completed_at)->not->toBeNull()
        ->and($updated->revision)->toBe(3)
        ->and(app(GetTaskAction::class)->execute($task, $actor)->title)->toBe('Approved draft')
        ->and(app(ListTasksAction::class)->execute($actor, TaskStatus::Completed, assignee: TaskActorData::fromAuthenticatable($assignee))->total())->toBe(1);

    expect(fn () => app(UpdateTaskAction::class)->execute($task, new UpdateTaskData(
        'Stale', TaskPriority::Low, TaskStatus::Open, 2,
    ), $actor))->toThrow(TaskRevisionConflict::class);

    expect(app(UnassignTaskAction::class)->execute($task, $assignee, $actor))->toBeTrue()
        ->and(app(UnassignTaskAction::class)->execute($task, $assignee, $actor))->toBeFalse();
});
