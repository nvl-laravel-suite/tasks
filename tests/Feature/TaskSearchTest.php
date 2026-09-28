<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Validation\ValidationException;
use Nvl\Tasks\Actions\AddTaskTagAction;
use Nvl\Tasks\Actions\AssignTaskAction;
use Nvl\Tasks\Actions\CreateTaskAction;
use Nvl\Tasks\Actions\ListTasksAction;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Contracts\TaskQueryScope;
use Nvl\Tasks\Data\Mutations\CreateTaskData;
use Nvl\Tasks\Data\Mutations\TaskTagMutationData;
use Nvl\Tasks\Data\Queries\TaskIndexQueryData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Enums\TaskCategory;
use Nvl\Tasks\Enums\TaskImportance;
use Nvl\Tasks\Enums\TaskPriority;
use Nvl\Tasks\Enums\TaskStatus;
use Nvl\Tasks\Enums\TaskType;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Tests\Fixtures\SearchTaskType;

function searchTask(string $title, array $attributes = []): Task
{
    return app(CreateTaskAction::class)->execute(
        new CreateTaskData($title, ...$attributes),
        TaskActorData::system(),
    );
}

function allowTaskSearch(): void
{
    app()->bind(TaskAuthorization::class, static fn () => new class implements TaskAuthorization
    {
        public function authorize(TaskAbility $ability, TaskActorData $actor, ?Task $task = null, ?Model $subject = null): void {}
    });
}

it('combines status, priority, type, category, and importance filters', function (): void {
    $matching = searchTask('Match', [
        'status' => TaskStatus::InProgress,
        'priority' => TaskPriority::High,
        'type' => TaskType::Review,
        'category' => TaskCategory::Project,
        'importance' => TaskImportance::Critical,
    ]);
    searchTask('Wrong category', [
        'status' => TaskStatus::InProgress,
        'priority' => TaskPriority::High,
        'type' => TaskType::Review,
        'category' => TaskCategory::Operations,
        'importance' => TaskImportance::Critical,
    ]);
    searchTask('Wrong status', [
        'status' => TaskStatus::Open,
        'priority' => TaskPriority::High,
        'type' => TaskType::Review,
        'category' => TaskCategory::Project,
        'importance' => TaskImportance::Critical,
    ]);

    $page = app(ListTasksAction::class)->execute(
        TaskActorData::system(),
        status: TaskStatus::InProgress,
        priority: 'high',
        type: TaskType::Review,
        category: 'project',
        importance: TaskImportance::Critical,
    );

    expect($page->total())->toBe(1)
        ->and($page->items()[0]->id)->toBe($matching->id);
});

it('accepts configured replacement enums and validates query values against them', function (): void {
    config()->set('tasks.enums.type', SearchTaskType::class);
    config()->set('tasks.defaults.type', SearchTaskType::Request->value);
    $incident = searchTask('Incident', ['type' => SearchTaskType::Incident]);
    searchTask('Request');

    $query = TaskIndexQueryData::validateAndCreate(['type' => 'incident']);
    $page = app(ListTasksAction::class)->execute(TaskActorData::system(), type: $query->type);

    expect($page->total())->toBe(1)
        ->and($page->items()[0]->id)->toBe($incident->id);
    expect(fn () => TaskIndexQueryData::validateAndCreate(['type' => 'review']))
        ->toThrow(ValidationException::class);
    expect(fn () => app(ListTasksAction::class)->execute(TaskActorData::system(), type: TaskType::Review))
        ->toThrow(ValidationException::class);
});

it('filters normalized package tags together with an assignee without duplicating tasks', function (): void {
    allowTaskSearch();
    $actor = TaskActorData::system();
    $matching = searchTask('Tagged and assigned');
    $other = searchTask('Tagged only');
    $assignee = new User;
    $assignee->setTable('users');
    $assignee->forceFill([
        'name' => 'Assignee',
        'email' => 'task-search-assignee@example.test',
        'password' => 'unused',
    ])->save();
    app(AddTaskTagAction::class)->execute($matching, new TaskTagMutationData('  Needs  Review ', 1), $actor);
    app(AddTaskTagAction::class)->execute($matching, new TaskTagMutationData('urgent', 2), $actor);
    app(AddTaskTagAction::class)->execute($other, new TaskTagMutationData('needs review', 1), $actor);
    app(AssignTaskAction::class)->execute($matching, $assignee, $actor);

    $page = app(ListTasksAction::class)->execute(
        $actor,
        assignee: TaskActorData::fromAuthenticatable($assignee),
        tag: ' NEEDS   REVIEW ',
    );

    expect($page->total())->toBe(1)
        ->and($page->items()[0]->id)->toBe($matching->id);
});

it('filters inclusive target and due date windows and overdue state', function (): void {
    CarbonImmutable::setTestNow('2026-09-28 12:00:00');

    try {
        $overdue = searchTask('Overdue', [
            'targetAt' => '2026-09-25 09:00:00',
            'dueAt' => '2026-09-27 18:00:00',
        ]);
        searchTask('Completed', [
            'status' => TaskStatus::Completed,
            'targetAt' => '2026-09-25 09:00:00',
            'dueAt' => '2026-09-27 18:00:00',
        ]);
        searchTask('Future', [
            'targetAt' => '2026-09-29 09:00:00',
            'dueAt' => '2026-09-30 18:00:00',
        ]);
        searchTask('Cancelled', [
            'status' => TaskStatus::Cancelled,
            'dueAt' => '2026-09-27 18:00:00',
        ]);
        searchTask('Undated');

        $page = app(ListTasksAction::class)->execute(
            TaskActorData::system(),
            targetFrom: '2026-09-25',
            targetTo: '2026-09-25',
            dueFrom: '2026-09-27',
            dueTo: '2026-09-27',
            overdue: true,
        );
        $notOverdue = app(ListTasksAction::class)->execute(TaskActorData::system(), overdue: false);

        expect($page->total())->toBe(1)
            ->and($page->items()[0]->id)->toBe($overdue->id)
            ->and($notOverdue->total())->toBe(4);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('retains the host query scope and page bounds when filtering', function (): void {
    app()->bind(TaskAuthorization::class, static fn () => new class implements TaskAuthorization, TaskQueryScope
    {
        public function authorize(TaskAbility $ability, TaskActorData $actor, ?Task $task = null, ?Model $subject = null): void {}

        public function scopeTasks(Builder $query, TaskActorData $actor): void
        {
            $query->where('title', 'Visible')->orWhere('title', 'Also visible');
        }
    });
    searchTask('Visible', ['priority' => TaskPriority::High]);
    searchTask('Also visible', ['priority' => TaskPriority::High]);
    searchTask('Hidden', ['priority' => TaskPriority::High]);

    $page = app(ListTasksAction::class)->execute(TaskActorData::system(), priority: 'high', perPage: 1);

    expect($page->total())->toBe(2)
        ->and($page->count())->toBe(1);
    expect(fn () => app(ListTasksAction::class)->execute(TaskActorData::system(), perPage: 101))
        ->toThrow(InvalidArgumentException::class);
});

it('validates calendar search input and keeps a false overdue filter', function (): void {
    $query = TaskIndexQueryData::validateAndCreate([
        'targetFrom' => '2026-09-27',
        'dueTo' => '2026-09-30',
        'overdue' => '0',
    ]);

    expect($query->targetFrom)->toBe('2026-09-27')
        ->and($query->dueTo)->toBe('2026-09-30')
        ->and($query->overdue)->toBeFalse();
    expect(fn () => TaskIndexQueryData::validateAndCreate(['targetFrom' => 'tomorrow']))
        ->toThrow(ValidationException::class);
    expect(fn () => app(ListTasksAction::class)->execute(
        TaskActorData::system(),
        dueFrom: '2026-09-30',
        dueTo: '2026-09-29',
    ))->toThrow(InvalidArgumentException::class);
});
