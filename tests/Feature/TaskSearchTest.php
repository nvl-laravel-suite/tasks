<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Validation\ValidationException;
use Nvl\Support\Enums\SystemActor;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Contracts\TaskQueryScope;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Enums\TaskCategory;
use Nvl\Tasks\Enums\TaskImportance;
use Nvl\Tasks\Enums\TaskPriority;
use Nvl\Tasks\Enums\TaskStatus;
use Nvl\Tasks\Enums\TaskType;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Services\TaskAssignments;
use Nvl\Tasks\Services\TaskLifecycle;
use Nvl\Tasks\Services\TaskReader;
use Nvl\Tasks\Services\TaskTags;
use Nvl\Tasks\Tests\Fixtures\SearchTaskType;

function searchTask(string $title, array $attributes = []): Task
{
    return app(TaskLifecycle::class)->create(
        ['title' => $title, ...$attributes],
        SystemActor::Background,
    );
}

function allowTaskSearch(): void
{
    app()->bind(TaskAuthorization::class, static fn () => new class implements TaskAuthorization
    {
        public function authorize(TaskAbility $ability, Authenticatable|SystemActor $actor, ?Task $task = null, ?Model $subject = null): void {}

        public function scopeTasks(Builder $query, Authenticatable|SystemActor $actor): void {}
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

    $page = app(TaskReader::class)->paginate(['status' => TaskStatus::InProgress, 'priority' => 'high', 'type' => TaskType::Review, 'category' => 'project', 'importance' => TaskImportance::Critical], SystemActor::Background);

    expect($page->total())->toBe(1)
        ->and($page->items()[0]->id)->toBe($matching->id);
});

it('accepts configured replacement enums and validates query values against them', function (): void {
    config()->set('nvl-tasks.enums.type', SearchTaskType::class);
    config()->set('nvl-tasks.defaults.type', SearchTaskType::Request->value);
    $incident = searchTask('Incident', ['type' => SearchTaskType::Incident]);
    searchTask('Request');

    $query = ['type' => 'incident'];
    $page = app(TaskReader::class)->paginate(['type' => $query['type']], SystemActor::Background);

    expect($page->total())->toBe(1)
        ->and($page->items()[0]->id)->toBe($incident->id);
    expect(fn () => app(TaskReader::class)->paginate(['type' => 'review'], SystemActor::Background))
        ->toThrow(ValidationException::class);
    expect(fn () => app(TaskReader::class)->paginate(['type' => TaskType::Review], SystemActor::Background))
        ->toThrow(ValidationException::class);
});

it('filters normalized package tags together with an assignee without duplicating tasks', function (): void {
    allowTaskSearch();
    $actor = SystemActor::Background;
    $matching = searchTask('Tagged and assigned');
    $other = searchTask('Tagged only');
    $assignee = new User;
    $assignee->setTable('users');
    $assignee->forceFill([
        'name' => 'Assignee',
        'email' => 'task-search-assignee@example.test',
        'password' => 'unused',
    ])->save();
    app(TaskTags::class)->add($matching, '  Needs  Review ', 1, $actor);
    app(TaskTags::class)->add($matching, 'urgent', 2, $actor);
    app(TaskTags::class)->add($other, 'needs review', 1, $actor);
    app(TaskAssignments::class)->assign($matching, $assignee, $actor);

    $page = app(TaskReader::class)->paginate(['assignee' => $assignee, 'tag' => ' NEEDS   REVIEW '], $actor);

    expect($page->total())->toBe(1)
        ->and($page->items()[0]->id)->toBe($matching->id);
});

it('filters inclusive target and due date windows and overdue state', function (): void {
    CarbonImmutable::setTestNow('2026-09-28 12:00:00');

    try {
        $overdue = searchTask('Overdue', [
            'target_at' => '2026-09-25 09:00:00',
            'due_at' => '2026-09-27 18:00:00',
        ]);
        searchTask('Completed', [
            'status' => TaskStatus::Completed,
            'target_at' => '2026-09-25 09:00:00',
            'due_at' => '2026-09-27 18:00:00',
        ]);
        searchTask('Future', [
            'target_at' => '2026-09-29 09:00:00',
            'due_at' => '2026-09-30 18:00:00',
        ]);
        searchTask('Cancelled', [
            'status' => TaskStatus::Cancelled,
            'due_at' => '2026-09-27 18:00:00',
        ]);
        searchTask('Undated');

        $page = app(TaskReader::class)->paginate(['target_from' => '2026-09-25', 'target_to' => '2026-09-25', 'due_from' => '2026-09-27', 'due_to' => '2026-09-27', 'overdue' => true], SystemActor::Background);
        $notOverdue = app(TaskReader::class)->paginate(['overdue' => false], SystemActor::Background);

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
        public function authorize(TaskAbility $ability, Authenticatable|SystemActor $actor, ?Task $task = null, ?Model $subject = null): void {}

        public function scopeTasks(Builder $query, Authenticatable|SystemActor $actor): void
        {
            $query->where('title', 'Visible')->orWhere('title', 'Also visible');
        }
    });
    searchTask('Visible', ['priority' => TaskPriority::High]);
    searchTask('Also visible', ['priority' => TaskPriority::High]);
    searchTask('Hidden', ['priority' => TaskPriority::High]);

    $page = app(TaskReader::class)->paginate(['priority' => 'high'], SystemActor::Background, perPage: 1);

    expect($page->total())->toBe(2)
        ->and($page->count())->toBe(1);
    expect(fn () => app(TaskReader::class)->paginate([], SystemActor::Background, perPage: 101))
        ->toThrow(InvalidArgumentException::class);
});

it('validates calendar search input and keeps a false overdue filter', function (): void {
    searchTask('Future', ['due_at' => '2099-01-01']);
    expect(app(TaskReader::class)->paginate(['overdue' => false], SystemActor::Background)->total())->toBe(1);
    expect(fn () => app(TaskReader::class)->paginate(['target_from' => 'tomorrow'], SystemActor::Background))->toThrow(InvalidArgumentException::class);
});
