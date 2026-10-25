<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Nvl\Media\Models\Media;
use Nvl\Media\Slots\MediaSlot;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Exceptions\BindingRequiredException;
use Nvl\Tasks\Contracts\TaskAttachments;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Contracts\TaskQueryScope;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Enums\TaskPriority;
use Nvl\Tasks\Enums\TaskStatus;
use Nvl\Tasks\Exceptions\TaskRevisionConflict;
use Nvl\Tasks\Integrations\MediaTaskAttachments;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskActivityOutbox;
use Nvl\Tasks\Models\TaskAssignment;
use Nvl\Tasks\Models\TaskChecklistItem;
use Nvl\Tasks\Models\TaskDependency;
use Nvl\Tasks\Models\TaskRelationship;
use Nvl\Tasks\Models\TaskTag;
use Nvl\Tasks\Models\TaskTimeEntry;
use Nvl\Tasks\Services\ConfiguredTaskAuthorization;
use Nvl\Tasks\Services\TaskAssignments;
use Nvl\Tasks\Services\TaskLifecycle;
use Nvl\Tasks\Services\TaskModelRegistry;
use Nvl\Tasks\Services\TaskPrincipalGuard;
use Nvl\Tasks\Services\TaskReader;

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
    app()->bind(TaskAuthorization::class, ConfiguredTaskAuthorization::class);
    expect(Route::has('nvl.tasks.management.index'))->toBeFalse();
    $actor = taskTestUser('Owner');

    expect(fn () => app(TaskLifecycle::class)->create(['title' => 'Review draft'], $actor))
        ->toThrow(BindingRequiredException::class);
});

it('registers bounded private task attachments', function (): void {
    $this->markTestSkipped('Native Media-enabled integration is certified with Task 12.');
    $slot = app(MediaTaskAttachments::class)->slot();

    expect($slot)->not->toBeNull()
        ->and($slot?->isPublic)->toBeFalse()
        ->and($slot?->sharingMode)->toBe(MediaSlot::SHARING_EXCLUSIVE)
        ->and($slot?->slotSizeLimit)->toBe(10)
        ->and($slot?->maxFileSize)->toBe(20 * 1024 * 1024);
});

it('uses Media for a private task attachment', function (): void {
    $this->markTestSkipped('Native Media-enabled integration is certified with Task 12.');
    Storage::fake('local');
    $task = app(TaskLifecycle::class)->create(['title' => 'Review assets'], SystemActor::Background);
    $media = Media::factory()->create(['mime_type' => 'text/plain', 'is_public' => false]);
    app(TaskAttachments::class)->attach($task, $media->id, SystemActor::Background);

    expect($media->is_public)->toBeFalse()
        ->and(app(TaskAttachments::class)->ids($task, SystemActor::Background))->toHaveCount(1);

    app(TaskLifecycle::class)->delete($task, SystemActor::Background);
    $restored = app(TaskLifecycle::class)->restore($task, 1, SystemActor::Background);

    expect(app(TaskAttachments::class)->ids($restored, SystemActor::Background))->toHaveCount(1);
});

it('keeps task details in its own model without Content or Metafields APIs', function (): void {
    $task = app(TaskLifecycle::class)->create(['title' => 'Review assets', 'description' => 'Check the source files.', 'metadata' => ['reference' => 'brief-123']], SystemActor::Background);

    expect($task->description)->toBe('Check the source files.')
        ->and($task->metadata)->toBe(['reference' => 'brief-123'])
        ->and(method_exists($task, 'contentPlacements'))->toBeFalse()
        ->and(method_exists($task, 'metafields'))->toBeFalse();
});

it('preserves a full task description through soft deletion and restoration', function (): void {
    $task = app(TaskLifecycle::class)->create(['title' => 'Review assets', 'description' => "Review the brief.\nRecord the decision."], SystemActor::Background);

    app(TaskLifecycle::class)->delete($task, SystemActor::Background);
    $restored = app(TaskLifecycle::class)->restore($task, 1, SystemActor::Background);

    expect($restored->description)->toBe("Review the brief.\nRecord the decision.");
});

it('stores bounded app hints on task metadata', function (): void {
    $task = app(TaskLifecycle::class)->create(['title' => 'Review assets', 'metadata' => ['source' => 'editorial', 'reference' => 'brief-123']], SystemActor::Background);

    expect($task->fresh()->metadata)->toBe(['source' => 'editorial', 'reference' => 'brief-123']);
});

it('reports missing host authorization in strict diagnostics', function (): void {
    app()->bind(TaskAuthorization::class, ConfiguredTaskAuthorization::class);
    $this->artisan('nvl:tasks:doctor', ['--strict' => true, '--format' => 'json'])
        ->assertFailed();
});

it('rejects oversized task metadata before persistence', function (): void {
    expect(fn () => app(TaskLifecycle::class)->create(['title' => 'Oversized', 'metadata' => ['payload' => str_repeat('x', 65_536)]], SystemActor::Background))->toThrow(ValidationException::class);

    expect(Task::query()->count())->toBe(0);
});

it('rejects titles that become empty after normalization', function (): void {
    expect(fn () => app(TaskLifecycle::class)->create(
        ['title' => '   '],
        SystemActor::Background,
    ))->toThrow(ValidationException::class);

    expect(Task::query()->count())->toBe(0);
});

it('lets a host authorization adapter scope task table rows to its actor', function (): void {
    app()->bind(TaskAuthorization::class, static fn () => new class implements TaskAuthorization, TaskQueryScope
    {
        public function authorize(TaskAbility $ability, Authenticatable|SystemActor $actor, ?Task $task = null, ?Model $subject = null): void {}

        public function scopeTasks(Builder $query, Authenticatable|SystemActor $actor): void
        {
            $query->where('creator_type', TaskPrincipalGuard::type($actor))
                ->where('creator_id', (string) $actor->id);
        }
    });

    $owner = taskTestUser('Owner');
    $other = taskTestUser('Other');
    app(TaskLifecycle::class)->create(['title' => 'Owner task'], $owner);
    app(TaskLifecycle::class)->create(['title' => 'Other task'], $other);

    $page = app(TaskReader::class)->paginate([], $owner);

    expect($page->total())->toBe(1)
        ->and($page->items()[0]->title)->toBe('Owner task');
});

it('keeps authorized task list queries constant and pages bounded as rows grow', function (): void {
    $actor = SystemActor::Background;
    $list = app(TaskReader::class);
    $taskModel = TaskModelRegistry::newInstance();
    $connection = $taskModel->getConnection();
    $assignee = taskTestUser('Budget assignee');
    $models = [$taskModel, $assignee, new TaskAssignment, new TaskChecklistItem,
        new TaskTimeEntry, new TaskTag, new TaskRelationship,
        new TaskDependency, new TaskActivityOutbox];
    $tables = array_map(static fn (Model $model): string => preg_quote($connection->getQueryGrammar()->wrapTable($model->getTable()), '/'), $models);
    $protectedRead = static fn (array $query): bool => preg_match('/\\b(?:from|join)\\s+(?:'.implode('|', $tables).')(?=\\s|\\)|$)/i', $query['query']) === 1;

    $task = app(TaskLifecycle::class)->create(['title' => 'Task 1'], $actor);
    app(TaskAssignments::class)->assign($task, $assignee, $actor);
    $list->paginate([], $actor, perPage: 4);

    $connection->enableQueryLog();

    try {
        $connection->flushQueryLog();
        $one = $list->paginate([], $actor, perPage: 4);
        $oneQueries = count(array_filter($connection->getQueryLog(), $protectedRead));

        foreach (range(2, 5) as $number) {
            $task = app(TaskLifecycle::class)->create(['title' => "Task {$number}"], $actor);
            app(TaskAssignments::class)->assign($task, $assignee, $actor);
        }

        $connection->flushQueryLog();
        $many = $list->paginate([], $actor, perPage: 4);
        $manyQueries = count(array_filter($connection->getQueryLog(), $protectedRead));
        $connection->flushQueryLog();
        $task->assignments()->get();
        $assignee->fresh();
        expect(count(array_filter($connection->getQueryLog(), $protectedRead)))->toBe(2);
    } finally {
        $connection->disableQueryLog();
        $connection->flushQueryLog();
    }

    expect($one->total())->toBe(1)
        ->and($many->total())->toBe(5)
        ->and($many->count())->toBe(4)
        ->and($oneQueries)->toBe(2)
        ->and($manyQueries)->toBe($oneQueries)
        ->toBeLessThanOrEqual(4);

    expect(fn () => $list->paginate([], $actor, perPage: 101))
        ->toThrow(InvalidArgumentException::class);
});

it('creates, updates, assigns and lists tasks without assuming the host user model', function (): void {
    app()->bind(TaskAuthorization::class, static fn () => new class implements TaskAuthorization
    {
        public function authorize(TaskAbility $ability, Authenticatable|SystemActor $actor, ?Task $task = null, ?Model $subject = null): void {}

        public function scopeTasks(Builder $query, Authenticatable|SystemActor $actor): void {}
    });

    $owner = taskTestUser('Owner');
    $assignee = taskTestUser('Assignee');
    $actor = $owner;
    $task = app(TaskLifecycle::class)->create(['title' => '  Review draft  ', 'metadata' => ['source' => 'editorial']], $actor);

    expect($task->title)->toBe('Review draft')
        ->and($task->status)->toBe(TaskStatus::Open)
        ->and($task->priority)->toBe(TaskPriority::Normal)
        ->and($task->creator?->getKey())->toBe($owner->getKey());

    $assignment = app(TaskAssignments::class)->assign($task, $assignee, $actor);
    $again = app(TaskAssignments::class)->assign($task, $assignee, $actor);

    expect($again->id)->toBe($assignment->id)
        ->and($task->fresh()?->revision)->toBe(2);

    $updated = app(TaskLifecycle::class)->update($task, ['title' => 'Approved draft', 'priority' => TaskPriority::High, 'status' => TaskStatus::Completed, 'metadata' => ['source' => 'editorial']], 2, $actor);

    expect($updated->status)->toBe(TaskStatus::Completed)
        ->and($updated->completed_at)->not->toBeNull()
        ->and($updated->revision)->toBe(3)
        ->and(app(TaskReader::class)->get($task, $actor)->title)->toBe('Approved draft')
        ->and(app(TaskReader::class)->paginate(['status' => TaskStatus::Completed, 'assignee' => $assignee], $actor)->total())->toBe(1);

    expect(fn () => app(TaskLifecycle::class)->update($task, ['title' => 'Stale', 'priority' => TaskPriority::Low, 'status' => TaskStatus::Open], 2, $actor))->toThrow(TaskRevisionConflict::class);

    expect(app(TaskAssignments::class)->unassign($task, $assignee, $actor))->toBeTrue()
        ->and(app(TaskAssignments::class)->unassign($task, $assignee, $actor))->toBeFalse();
});
