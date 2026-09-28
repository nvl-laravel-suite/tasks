<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Tasks\Actions\CreateTaskAction;
use Nvl\Tasks\Actions\GetTaskAction;
use Nvl\Tasks\Actions\ListTasksAction;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Contracts\TaskQueryScope;
use Nvl\Tasks\Data\Mutations\CreateTaskData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Definitions\Tables\TasksTables;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskActivityOutbox;
use Nvl\Tasks\Models\TaskAssignment;
use Nvl\Tasks\Models\TaskChecklistItem;
use Nvl\Tasks\Models\TaskDependency;
use Nvl\Tasks\Models\TaskRelationship;
use Nvl\Tasks\Models\TaskTag;
use Nvl\Tasks\Models\TaskTimeEntry;
use Nvl\Tasks\Services\TasksActivityDelivery;
use Nvl\Tasks\Support\TasksConfiguration;
use Nvl\Tasks\Tenancy\TasksAdoptionAdapter;
use Nvl\Tasks\Tests\TenancyTestCase;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantAssignment;
use Nvl\Tenancy\ValueObjects\TenantId;

it('isolates task creation and reads after tenant adoption', function (): void {
    $coordinator = app(TenantAdoptionCoordinator::class);
    $operation = new PlatformOperation('tasks.test.adoption', 'test', 'pest');
    $plan = $coordinator->prepare(['media', 'activity', 'tasks'], [], $operation);

    $backfilled = false;

    for ($batch = 0; $batch < 20 && ! $backfilled; $batch++) {
        $backfilled = $coordinator->backfill($plan, 100, $operation);
    }

    expect($backfilled)->toBeTrue()
        ->and($coordinator->verify($plan)->passed())->toBeTrue();
    $coordinator->activate($plan, $operation);
    app(MaintenanceMode::class)->deactivate();

    $runner = app(TenantRunner::class);
    $actor = TaskActorData::system();
    $task = $runner->run(new TenantId(TenancyTestCase::TENANT_A),
        static fn () => app(CreateTaskAction::class)->execute(new CreateTaskData('Tenant A task'), $actor));

    expect($task->tenant_id)->toBe(TenancyTestCase::TENANT_A);

    app()->bind(TaskAuthorization::class, static fn () => new class implements TaskAuthorization, TaskQueryScope
    {
        public function authorize(TaskAbility $ability, TaskActorData $actor, ?Task $task = null, ?Model $subject = null): void {}

        public function scopeTasks(Builder $query, TaskActorData $actor): void
        {
            $query->where('title', 'No such task')->orWhere('title', 'Tenant A task');
        }
    });

    $runner->run(new TenantId(TenancyTestCase::TENANT_B), function () use ($actor, $task): void {
        expect(app(ListTasksAction::class)->execute($actor)->total())->toBe(0)
            ->and(fn () => app(GetTaskAction::class)->execute($task, $actor))
            ->toThrow(ModelNotFoundException::class);
    });

    expect($runner->run(new TenantId(TenancyTestCase::TENANT_A),
        static fn () => app(GetTaskAction::class)->execute($task, $actor)->id))->toBe($task->id);

    $outboxId = $runner->run(new TenantId(TenancyTestCase::TENANT_A), static function () use ($task): string {
        $outbox = app(TenantBoundary::class)->query(TaskActivityOutbox::query(), TaskActivityOutbox::TENANT_RESOURCE)
            ->where('task_id', $task->id)->firstOrFail();
        $outbox->forceFill(['delivered_at' => null, 'available_at' => now()->subSecond()])->save();

        return $outbox->id;
    });

    expect($runner->run(new TenantId(TenancyTestCase::TENANT_B),
        static fn (): bool => app(TasksActivityDelivery::class)->deliver($outboxId)))->toBeFalse()
        ->and($runner->run(new TenantId(TenancyTestCase::TENANT_A),
            static fn (): bool => app(TasksActivityDelivery::class)->deliver($outboxId)))->toBeTrue()
        ->and(TaskActivityOutbox::query()->findOrFail($outboxId)->attempts)->toBe(1);

    $foreignActor = new TaskActorData($task->getMorphClass(), $task->id, principal: $task);

    expect(fn () => $runner->run(new TenantId(TenancyTestCase::TENANT_B),
        static fn () => app(CreateTaskAction::class)->execute(new CreateTaskData('Forged causer'), $foreignActor)))
        ->toThrow(TenantBoundaryViolation::class);
    expect(Task::query()->where('title', 'Forged causer')->exists())->toBeFalse();

    $assignment = new TaskAssignment;
    $assignment->forceFill([
        'task_id' => $task->id,
        'tenant_id' => TenancyTestCase::TENANT_A,
        'assignee_type' => 'test-principal',
        'assignee_id' => '1',
    ])->save();
    DB::connection(TasksConfiguration::connection())
        ->table(TasksConfiguration::table(TasksTables::Assignments))
        ->where('id', $assignment->id)
        ->update(['tenant_id' => TenancyTestCase::TENANT_B]);

    expect(app(TasksAdoptionAdapter::class)->verify($plan)->errors)
        ->toContain('tasks.assignments.tenant_id');
});

it('adopts every task child and keeps inherited reads inside the active tenant', function (): void {
    $owner = new Task;
    $owner->forceFill(['title' => 'Owner task'])->save();
    $related = new Task;
    $related->forceFill(['title' => 'Related task'])->save();

    $item = new TaskChecklistItem;
    $item->forceFill(['task_id' => $owner->id, 'title' => 'Review', 'position' => 1])->save();
    $entry = new TaskTimeEntry;
    $entry->forceFill([
        'task_id' => $owner->id,
        'performer_type' => 'test-principal',
        'performer_id' => '1',
        'started_at' => now()->subHour(),
        'stopped_at' => now(),
        'duration_seconds' => 3600,
    ])->save();
    $tag = new TaskTag;
    $tag->forceFill(['task_id' => $owner->id, 'tag' => 'review'])->save();
    $relationship = new TaskRelationship;
    $relationship->forceFill(['child_task_id' => $owner->id, 'parent_task_id' => $related->id])->save();
    $dependency = new TaskDependency;
    $dependency->forceFill(['task_id' => $owner->id, 'blocker_task_id' => $related->id])->save();

    $operation = new PlatformOperation('tasks.test.children', 'test', 'pest');
    $coordinator = app(TenantAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['media', 'activity', 'tasks'], [
        new TenantAssignment('tasks.tasks', $owner->id, new TenantId(TenancyTestCase::TENANT_A)),
        new TenantAssignment('tasks.tasks', $related->id, new TenantId(TenancyTestCase::TENANT_A)),
    ], $operation);

    $backfilled = false;

    for ($batch = 0; $batch < 20 && ! $backfilled; $batch++) {
        $backfilled = $coordinator->backfill($plan, 100, $operation);
    }

    expect($backfilled)->toBeTrue()
        ->and($coordinator->verify($plan)->passed())->toBeTrue();

    foreach ([$item, $entry, $tag, $relationship, $dependency] as $child) {
        expect($child->fresh()?->tenant_id)->toBe(TenancyTestCase::TENANT_A);
    }

    $coordinator->activate($plan, $operation);
    app(MaintenanceMode::class)->deactivate();
    $runner = app(TenantRunner::class);
    $boundary = app(TenantBoundary::class);
    $resources = [
        ['tasks.checklist_items', TaskChecklistItem::class],
        ['tasks.time_entries', TaskTimeEntry::class],
        ['tasks.tags', TaskTag::class],
        ['tasks.relationships', TaskRelationship::class],
        ['tasks.dependencies', TaskDependency::class],
    ];

    foreach ([TenancyTestCase::TENANT_A => 1, TenancyTestCase::TENANT_B => 0] as $tenant => $expectedCount) {
        $runner->run(new TenantId($tenant), function () use ($boundary, $expectedCount, $resources): void {
            foreach ($resources as [$resource, $model]) {
                expect($boundary->query($model::query(), $resource)->count())->toBe($expectedCount);
            }
        });
    }

    foreach ([
        [$item, 'tasks.checklist_items.tenant_id'],
        [$entry, 'tasks.time_entries.tenant_id'],
        [$tag, 'tasks.tags.tenant_id'],
        [$relationship, 'tasks.relationships.tenant_id'],
        [$dependency, 'tasks.dependencies.tenant_id'],
    ] as [$child, $error]) {
        DB::table($child->getTable())->where('id', $child->id)
            ->update(['tenant_id' => TenancyTestCase::TENANT_B]);
        expect(app(TasksAdoptionAdapter::class)->verify($plan)->errors)->toContain($error);
        DB::table($child->getTable())->where('id', $child->id)
            ->update(['tenant_id' => TenancyTestCase::TENANT_A]);
    }

    DB::table($related->getTable())->where('id', $related->id)
        ->update(['tenant_id' => TenancyTestCase::TENANT_B]);

    expect(app(TasksAdoptionAdapter::class)->verify($plan)->errors)
        ->toContain('tasks.relationships.parent_task_id', 'tasks.dependencies.blocker_task_id');
});

it('adopts outbox rows from their tasks and detects conflicting ownership', function (): void {
    $task = new Task;
    $task->forceFill(['title' => 'Outbox owner'])->save();
    $outbox = new TaskActivityOutbox;
    $outbox->forceFill([
        'task_id' => $task->id,
        'payload' => ['event' => 'task.created'],
        'available_at' => now(),
    ])->save();

    $operation = new PlatformOperation('tasks.test.outbox', 'test', 'pest');
    $coordinator = app(TenantAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['media', 'activity', 'tasks'], [
        new TenantAssignment('tasks.tasks', $task->id, new TenantId(TenancyTestCase::TENANT_A)),
    ], $operation);

    $backfilled = false;

    for ($batch = 0; $batch < 20 && ! $backfilled; $batch++) {
        $backfilled = $coordinator->backfill($plan, 100, $operation);
    }

    expect($backfilled)->toBeTrue()
        ->and($outbox->fresh()?->tenant_id)->toBe(TenancyTestCase::TENANT_A)
        ->and($coordinator->verify($plan)->passed())->toBeTrue();

    DB::table($outbox->getTable())->where('id', $outbox->id)
        ->update(['tenant_id' => TenancyTestCase::TENANT_B]);

    expect(app(TasksAdoptionAdapter::class)->verify($plan)->errors)
        ->toContain('tasks.activity_outbox.tenant_id');

    DB::table($outbox->getTable())->where('id', $outbox->id)
        ->update(['tenant_id' => TenancyTestCase::TENANT_A]);
    $coordinator->activate($plan, $operation);
    app(MaintenanceMode::class)->deactivate();

    $runner = app(TenantRunner::class);
    $boundary = app(TenantBoundary::class);

    expect($runner->run(new TenantId(TenancyTestCase::TENANT_A),
        static fn (): int => $boundary->query(TaskActivityOutbox::query(), TaskActivityOutbox::TENANT_RESOURCE)->count()))->toBe(1)
        ->and($runner->run(new TenantId(TenancyTestCase::TENANT_B),
            static fn (): int => $boundary->query(TaskActivityOutbox::query(), TaskActivityOutbox::TENANT_RESOURCE)->count()))->toBe(0);
});

it('blocks adoption when an outbox row no longer has a task', function (): void {
    $outbox = new TaskActivityOutbox;
    $outbox->forceFill([
        'task_id' => (string) Str::uuid(),
        'payload' => ['event' => 'task.deleted'],
        'available_at' => now(),
    ])->save();

    $operation = new PlatformOperation('tasks.test.orphan-outbox', 'test', 'pest');
    $coordinator = app(TenantAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['media', 'activity', 'tasks'], [], $operation);

    $backfilled = false;

    for ($batch = 0; $batch < 20 && ! $backfilled; $batch++) {
        $backfilled = $coordinator->backfill($plan, 100, $operation);
    }

    expect($backfilled)->toBeTrue()
        ->and(app(TasksAdoptionAdapter::class)->verify($plan)->errors)
        ->toContain('tasks.activity_outbox.task_id')
        ->and($outbox->fresh())->not->toBeNull();
});
