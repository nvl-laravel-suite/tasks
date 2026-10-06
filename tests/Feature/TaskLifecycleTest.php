<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Nvl\Media\Models\Media;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Tasks\Actions\AssignTaskAction;
use Nvl\Tasks\Actions\CreateTaskAction;
use Nvl\Tasks\Actions\DeleteTaskAction;
use Nvl\Tasks\Actions\GetTaskAction;
use Nvl\Tasks\Actions\ListTasksAction;
use Nvl\Tasks\Actions\RestoreTaskAction;
use Nvl\Tasks\Actions\UnassignTaskAction;
use Nvl\Tasks\Actions\UpdateTaskAction;
use Nvl\Tasks\Contracts\TaskAttachments;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Contracts\TaskQueryScope;
use Nvl\Tasks\Data\Mutations\CreateTaskData;
use Nvl\Tasks\Data\Mutations\UpdateTaskData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Enums\TaskPriority;
use Nvl\Tasks\Enums\TaskStatus;
use Nvl\Tasks\Exceptions\TaskRevisionConflict;
use Nvl\Tasks\Integrations\MediaTaskAttachments;
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

it('registers bounded private task attachments', function (): void {
    $slot = app(MediaTaskAttachments::class)->slot();

    expect($slot)->not->toBeNull()
        ->and($slot?->isPublic)->toBeFalse()
        ->and($slot?->sharingMode)->toBe(MediaSlot::SHARING_EXCLUSIVE)
        ->and($slot?->slotSizeLimit)->toBe(10)
        ->and($slot?->maxFileSize)->toBe(20 * 1024 * 1024);
});

it('uses Media for a private task attachment', function (): void {
    Storage::fake('local');
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Review assets'), TaskActorData::system());
    $media = Media::factory()->create(['mime_type' => 'text/plain', 'is_public' => false]);
    app(TaskAttachments::class)->attach($task, $media->id, TaskActorData::system());

    expect($media->is_public)->toBeFalse()
        ->and(app(TaskAttachments::class)->ids($task, TaskActorData::system()))->toHaveCount(1);

    app(DeleteTaskAction::class)->execute($task, TaskActorData::system());
    $restored = app(RestoreTaskAction::class)->execute($task, 1, TaskActorData::system());

    expect(app(TaskAttachments::class)->ids($restored, TaskActorData::system()))->toHaveCount(1);
});

it('keeps task details in its own model without Content or Metafields APIs', function (): void {
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData(
        title: 'Review assets',
        description: 'Check the source files.',
        metadata: ['reference' => 'brief-123'],
    ), TaskActorData::system());

    expect($task->description)->toBe('Check the source files.')
        ->and($task->metadata)->toBe(['reference' => 'brief-123'])
        ->and(method_exists($task, 'contentPlacements'))->toBeFalse()
        ->and(method_exists($task, 'metafields'))->toBeFalse();
});

it('preserves a full task description through soft deletion and restoration', function (): void {
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData(
        title: 'Review assets',
        description: "Review the brief.\nRecord the decision.",
    ), TaskActorData::system());

    app(DeleteTaskAction::class)->execute($task, TaskActorData::system());
    $restored = app(RestoreTaskAction::class)->execute($task, 1, TaskActorData::system());

    expect($restored->description)->toBe("Review the brief.\nRecord the decision.");
});

it('stores bounded app hints on task metadata', function (): void {
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData(
        title: 'Review assets',
        metadata: ['source' => 'editorial', 'reference' => 'brief-123'],
    ), TaskActorData::system());

    expect($task->fresh()->metadata)->toBe(['source' => 'editorial', 'reference' => 'brief-123']);
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

it('keeps authorized task list queries constant and pages bounded as rows grow', function (): void {
    $actor = TaskActorData::system();
    $list = app(ListTasksAction::class);
    $connection = DB::connection();

    app(CreateTaskAction::class)->execute(new CreateTaskData('Task 1'), $actor);
    $list->execute($actor, perPage: 4);

    $connection->enableQueryLog();

    try {
        $connection->flushQueryLog();
        $one = $list->execute($actor, perPage: 4);
        $oneQueries = count($connection->getQueryLog());

        foreach (range(2, 5) as $number) {
            app(CreateTaskAction::class)->execute(new CreateTaskData("Task {$number}"), $actor);
        }

        $connection->flushQueryLog();
        $many = $list->execute($actor, perPage: 4);
        $manyQueries = count($connection->getQueryLog());
    } finally {
        $connection->disableQueryLog();
        $connection->flushQueryLog();
    }

    expect($one->total())->toBe(1)
        ->and($many->total())->toBe(5)
        ->and($many->count())->toBe(4)
        ->and($manyQueries)->toBe($oneQueries)
        ->toBeLessThanOrEqual(4);

    expect(fn () => $list->execute($actor, perPage: 101))
        ->toThrow(InvalidArgumentException::class);
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
