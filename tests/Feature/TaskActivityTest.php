<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Activity\Definitions\Tables\ActivityTables;
use Nvl\Activity\Facades\ActivityLog as ActivityWriter;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Support\ActivityRecordEnvelope;
use Nvl\Tasks\Actions\AssignTaskAction;
use Nvl\Tasks\Actions\CreateTaskAction;
use Nvl\Tasks\Actions\DeleteTaskAction;
use Nvl\Tasks\Actions\RestoreTaskAction;
use Nvl\Tasks\Actions\UnassignTaskAction;
use Nvl\Tasks\Actions\UpdateTaskAction;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Data\Mutations\CreateTaskData;
use Nvl\Tasks\Data\Mutations\UpdateTaskData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Enums\TaskPriority;
use Nvl\Tasks\Enums\TaskStatus;
use Nvl\Tasks\Exceptions\TaskRevisionConflict;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskActivityOutbox;
use Nvl\Tasks\Services\TasksActivityDelivery;

function activityTestUser(string $name): User
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

function allowTaskActivityMutations(): void
{
    app()->bind(TaskAuthorization::class, static fn () => new class implements TaskAuthorization
    {
        public function authorize(TaskAbility $ability, TaskActorData $actor, ?Task $task = null, ?Model $subject = null): void {}
    });
}

function createTaskActivityAuditTable(): void
{
    Schema::connection('task_activity_audit')->create(ActivityTables::get(ActivityTables::ActivityLog), function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('log_name')->nullable();
        $table->text('description');
        $table->string('subject_type')->nullable();
        $table->string('subject_id')->nullable();
        $table->string('event')->nullable();
        $table->string('causer_type')->nullable();
        $table->string('causer_id')->nullable();
        $table->json('attribute_changes')->nullable();
        $table->json('properties')->nullable();
        $table->uuid('batch_uuid')->nullable();
        $table->timestamps();
    });
}

it('records one attributed activity for each task lifecycle mutation', function (): void {
    allowTaskActivityMutations();
    $actor = TaskActorData::fromAuthenticatable(activityTestUser('Owner'));
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Draft'), $actor);
    $updated = app(UpdateTaskAction::class)->execute($task, new UpdateTaskData(
        title: 'Reviewed',
        priority: TaskPriority::Normal,
        status: TaskStatus::Open,
        expectedRevision: 1,
    ), $actor);
    app(DeleteTaskAction::class)->execute($task, $actor);
    app(RestoreTaskAction::class)->execute($task, $updated->revision, $actor);

    $activities = ActivityLog::query()->where('subject_type', $task->getMorphClass())
        ->where('subject_id', $task->id)->orderBy('id')->get();

    expect($activities->pluck('event')->all())->toEqualCanonicalizing(['created', 'updated', 'deleted', 'restored'])
        ->and($activities)->toHaveCount(4)
        ->and($activities->every(static fn (ActivityLog $activity): bool => $activity->causer_type === $actor->type
            && (string) $activity->causer_id === (string) $actor->id
            && $activity->properties?->get('context')['actor_type'] === $actor->type
        ))->toBeTrue();

    $update = $activities->firstWhere('event', 'updated');
    expect($update?->properties?->get('attributes')['title'] ?? null)->toBe('Reviewed')
        ->and($update?->properties?->get('old')['title'] ?? null)->toBe('Draft');
});

it('records assignment changes once and skips idempotent repeats', function (): void {
    allowTaskActivityMutations();
    $actor = TaskActorData::fromAuthenticatable(activityTestUser('Owner'));
    $assignee = activityTestUser('Assignee');
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Draft'), $actor);

    app(AssignTaskAction::class)->execute($task, $assignee, $actor);
    app(AssignTaskAction::class)->execute($task, $assignee, $actor);
    app(UnassignTaskAction::class)->execute($task, $assignee, $actor);
    app(UnassignTaskAction::class)->execute($task, $assignee, $actor);

    $activities = ActivityLog::query()->where('subject_id', $task->id)->get();

    expect($activities->pluck('event')->all())->toEqualCanonicalizing(['created', 'assigned', 'unassigned'])
        ->and($activities)->toHaveCount(3);

    $assignment = $activities->firstWhere('event', 'assigned');
    expect($assignment?->properties?->get('context')['assignee_type'] ?? null)->toBe($assignee->getMorphClass())
        ->and($assignment?->properties?->get('context')['assignee_id'] ?? null)->toBe((string) $assignee->getKey());
});

it('does not record activity for rejected stale writes', function (): void {
    allowTaskActivityMutations();
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Draft'), TaskActorData::system());

    expect(fn () => app(UpdateTaskAction::class)->execute($task, new UpdateTaskData(
        title: 'Rejected',
        priority: TaskPriority::Normal,
        status: TaskStatus::Open,
        expectedRevision: 2,
    ), TaskActorData::system()))->toThrow(TaskRevisionConflict::class);

    $activities = ActivityLog::query()->where('subject_id', $task->id)->get();

    expect($activities)->toHaveCount(1)
        ->and($activities->first()?->properties?->get('source'))->toBe('system');
});

it('omits update activity when only technical revision fields changed', function (): void {
    allowTaskActivityMutations();
    $actor = TaskActorData::fromAuthenticatable(activityTestUser('Owner'));
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Draft'), $actor);

    app(UpdateTaskAction::class)->execute($task, new UpdateTaskData(
        title: 'Draft',
        priority: TaskPriority::Normal,
        status: TaskStatus::Open,
        expectedRevision: 1,
    ), $actor);

    expect(ActivityLog::query()->where('subject_id', $task->id)->pluck('event')->all())->toBe(['created']);
});

it('keeps manually supplied actor identity as structured scalar attribution', function (): void {
    allowTaskActivityMutations();
    $actor = new TaskActorData('external-principal', 'person-42');
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Draft'), $actor);
    $activity = ActivityLog::query()->where('subject_id', $task->id)->firstOrFail();

    expect($activity->causer_type)->toBeNull()
        ->and($activity->properties?->get('actor_id'))->toBe('person-42')
        ->and($activity->properties?->get('context')['actor_type'])->toBe('external-principal');
});

it('keeps authenticated principal models out of actor payloads and rejects mismatches', function (): void {
    $user = activityTestUser('Owner');
    $actor = TaskActorData::fromAuthenticatable($user);

    expect($actor->principal())->toBe($user)
        ->and($actor->toArray())->not->toHaveKey('principal');

    expect(fn () => new TaskActorData($user->getMorphClass(), 'different-id', principal: $user))
        ->toThrow(InvalidArgumentException::class);
});

it('rolls back a task when atomic same-connection activity cannot be recorded', function (): void {
    config()->set('nvl-activity.storage.table', 'missing_activity_table');

    expect(fn () => app(CreateTaskAction::class)->execute(new CreateTaskData('Rejected task'), TaskActorData::system()))
        ->toThrow(QueryException::class);

    expect(Task::query()->where('title', 'Rejected task')->exists())->toBeFalse()
        ->and(TaskActivityOutbox::query()->count())->toBe(0);
});

it('retains and retries a committed task event while Activity uses another connection', function (): void {
    config()->set('database.connections.task_activity_audit', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('nvl-activity.storage.connection', 'task_activity_audit');
    $occurredAt = CarbonImmutable::parse('2026-09-28 10:00:00 UTC');
    CarbonImmutable::setTestNow($occurredAt);

    try {
        $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Kept task'), TaskActorData::system());
        $pending = TaskActivityOutbox::query()->where('task_id', $task->id)->firstOrFail();

        expect($pending->delivered_at)->toBeNull()
            ->and(app(TasksActivityDelivery::class)->deliver($pending->id))->toBeFalse()
            ->and($pending->fresh()?->attempts)->toBe(1)
            ->and(Task::query()->find($task->id)?->title)->toBe('Kept task');

        createTaskActivityAuditTable();
        CarbonImmutable::setTestNow($occurredAt->addHour());

        expect(app(TasksActivityDelivery::class)->deliver($pending->id))->toBeTrue()
            ->and(app(TasksActivityDelivery::class)->deliver($pending->id))->toBeTrue()
            ->and(DB::connection('task_activity_audit')->table(ActivityTables::get(ActivityTables::ActivityLog))->count())->toBe(1)
            ->and($pending->fresh()?->delivered_at)->not->toBeNull()
            ->and((string) DB::connection('task_activity_audit')->table(ActivityTables::get(ActivityTables::ActivityLog))->value('created_at'))
            ->toContain('2026-09-28 10:00:00');
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('does not write Activity on another connection before an outer task transaction commits', function (): void {
    config()->set('database.connections.task_activity_audit', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('nvl-activity.storage.connection', 'task_activity_audit');
    createTaskActivityAuditTable();

    expect(fn () => DB::transaction(function (): void {
        app(CreateTaskAction::class)->execute(new CreateTaskData('Rolled back'), TaskActorData::system());

        throw new RuntimeException('Rollback outer task transaction');
    }))->toThrow(RuntimeException::class);

    expect(DB::connection('task_activity_audit')->table(ActivityTables::get(ActivityTables::ActivityLog))->count())->toBe(0);
    expect(TaskActivityOutbox::query()->count())->toBe(0);
});

it('replays a written but unacknowledged event through the recovery command exactly once', function (): void {
    config()->set('database.connections.task_activity_audit', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('nvl-activity.storage.connection', 'task_activity_audit');

    $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Recoverable task'), TaskActorData::system());
    $pending = TaskActivityOutbox::query()->where('task_id', $task->id)->firstOrFail();
    createTaskActivityAuditTable();
    $pending->forceFill(['available_at' => CarbonImmutable::now()->subSecond()])->save();
    ActivityWriter::recordEnvelope(ActivityRecordEnvelope::fromArray($pending->payload));

    expect($pending->delivered_at)->toBeNull()
        ->and(Artisan::call('nvl:tasks:activity:drain'))->toBe(0)
        ->and($pending->fresh()?->delivered_at)->not->toBeNull()
        ->and($pending->fresh()?->attempts)->toBeGreaterThanOrEqual(1)
        ->and(DB::connection('task_activity_audit')->table(ActivityTables::get(ActivityTables::ActivityLog))->count())->toBe(1);

    Artisan::call('nvl:tasks:activity:drain');

    expect(DB::connection('task_activity_audit')->table(ActivityTables::get(ActivityTables::ActivityLog))->count())->toBe(1);
});

it('rejects invalid recovery batch sizes', function (): void {
    expect(Artisan::call('nvl:tasks:activity:drain', ['--limit' => '0']))->toBe(1)
        ->and(Artisan::call('nvl:tasks:activity:drain', ['--limit' => '01']))->toBe(1)
        ->and(Artisan::call('nvl:tasks:activity:drain', ['--limit' => '1001']))->toBe(1);
});

it('keeps a committed event when immediate queue dispatch is unavailable', function (): void {
    config()->set('database.connections.task_activity_audit', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('nvl-activity.storage.connection', 'task_activity_audit');
    config()->set('queue.default', 'unavailable_task_activity_queue');

    $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Committed despite queue outage'), TaskActorData::system());
    $pending = TaskActivityOutbox::query()->where('task_id', $task->id)->firstOrFail();

    expect($task->exists)->toBeTrue()
        ->and($pending->delivered_at)->toBeNull()
        ->and($pending->attempts)->toBe(0);
});
