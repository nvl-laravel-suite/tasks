<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
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
use Nvl\Tasks\Models\TaskAssignment;
use Nvl\Tasks\Support\TasksConfiguration;
use Nvl\Tasks\Tenancy\TasksAdoptionAdapter;
use Nvl\Tasks\Tests\TenancyTestCase;
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantId;

it('isolates task creation and reads after tenant adoption', function (): void {
    $coordinator = app(TenantAdoptionCoordinator::class);
    $operation = new PlatformOperation('tasks.test.adoption', 'test', 'pest');
    $plan = $coordinator->prepare(['media', 'content', 'metafields', 'tasks'], [], $operation);

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
