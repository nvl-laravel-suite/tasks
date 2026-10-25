<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Activity\Definitions\Tables\ActivityTables;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Activity\Services\ActivityRecorder;
use Nvl\Activity\Support\ActivityRecordEnvelope;
use Nvl\Support\Enums\SystemActor;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Enums\TaskPriority;
use Nvl\Tasks\Enums\TaskStatus;
use Nvl\Tasks\Exceptions\TaskRevisionConflict;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskActivityOutbox;
use Nvl\Tasks\Services\TaskAssignments;
use Nvl\Tasks\Services\TaskLifecycle;
use Nvl\Tasks\Services\TaskPrincipalGuard;
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
        public function authorize(TaskAbility $ability, Authenticatable|SystemActor $actor, ?Task $task = null, ?Model $subject = null): void {}

        public function scopeTasks(Builder $query, Authenticatable|SystemActor $actor): void {}
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
    $actor = activityTestUser('Owner');
    $task = app(TaskLifecycle::class)->create(['title' => 'Draft'], $actor);
    $updated = app(TaskLifecycle::class)->update($task, ['title' => 'Reviewed', 'priority' => TaskPriority::Normal, 'status' => TaskStatus::Open], 1, $actor);
    app(TaskLifecycle::class)->delete($task, $actor);
    app(TaskLifecycle::class)->restore($task, $updated->revision, $actor);

    $activities = ActivityLog::query()->where('subject_type', $task->getMorphClass())
        ->where('subject_id', $task->id)->orderBy('id')->get();

    expect($activities->pluck('event')->all())->toEqualCanonicalizing(['created', 'updated', 'deleted', 'restored'])
        ->and($activities)->toHaveCount(4)
        ->and($activities->every(static fn (ActivityLog $activity): bool => $activity->causer_type === TaskPrincipalGuard::type($actor)
            && (string) $activity->causer_id === (string) $actor->id
            && $activity->properties?->get('context')['actor_type'] === TaskPrincipalGuard::type($actor)
        ))->toBeTrue();

    $update = $activities->firstWhere('event', 'updated');
    expect($update?->properties?->get('attributes')['title'] ?? null)->toBe('Reviewed')
        ->and($update?->properties?->get('old')['title'] ?? null)->toBe('Draft');
});

it('records assignment changes once and skips idempotent repeats', function (): void {
    allowTaskActivityMutations();
    $actor = activityTestUser('Owner');
    $assignee = activityTestUser('Assignee');
    $task = app(TaskLifecycle::class)->create(['title' => 'Draft'], $actor);

    app(TaskAssignments::class)->assign($task, $assignee, $actor);
    app(TaskAssignments::class)->assign($task, $assignee, $actor);
    app(TaskAssignments::class)->unassign($task, $assignee, $actor);
    app(TaskAssignments::class)->unassign($task, $assignee, $actor);

    $activities = ActivityLog::query()->where('subject_id', $task->id)->get();

    expect($activities->pluck('event')->all())->toEqualCanonicalizing(['created', 'assigned', 'unassigned'])
        ->and($activities)->toHaveCount(3);

    $assignment = $activities->firstWhere('event', 'assigned');
    expect($assignment?->properties?->get('context')['assignee_type'] ?? null)->toBe($assignee->getMorphClass())
        ->and($assignment?->properties?->get('context')['assignee_id'] ?? null)->toBe((string) $assignee->getKey());
});

it('does not record activity for rejected stale writes', function (): void {
    allowTaskActivityMutations();
    $task = app(TaskLifecycle::class)->create(['title' => 'Draft'], SystemActor::Background);

    expect(fn () => app(TaskLifecycle::class)->update($task, ['title' => 'Rejected', 'priority' => TaskPriority::Normal, 'status' => TaskStatus::Open], 2, SystemActor::Background))->toThrow(TaskRevisionConflict::class);

    $activities = ActivityLog::query()->where('subject_id', $task->id)->get();

    expect($activities)->toHaveCount(1)
        ->and($activities->first()?->properties?->get('source'))->toBe('system');
});

it('omits update activity when only technical revision fields changed', function (): void {
    allowTaskActivityMutations();
    $actor = activityTestUser('Owner');
    $task = app(TaskLifecycle::class)->create(['title' => 'Draft'], $actor);

    app(TaskLifecycle::class)->update($task, ['title' => 'Draft', 'priority' => TaskPriority::Normal, 'status' => TaskStatus::Open], 1, $actor);

    expect(ActivityLog::query()->where('subject_id', $task->id)->pluck('event')->all())->toBe(['created']);
});

it('keeps manually supplied actor identity as structured scalar attribution', function (): void {
    $actor = activityTestUser('Attribution');
    $task = app(TaskLifecycle::class)->create(['title' => 'Draft'], $actor);
    $event = TaskActivityOutbox::query()->where('task_id', $task->id)->firstOrFail();
    $id = (string) $actor->id;
    $actor->delete();
    $event->forceFill(['delivered_at' => null])->save();
    expect(app(TasksActivityDelivery::class)->deliver($event->id))->toBeTrue();
    expect(ActivityLog::query()->where('subject_id', $task->id)->firstOrFail()->causer_id)->toBe($id);
});

it('keeps authenticated principal models out of actor payloads and rejects mismatches', function (): void {
    $user = activityTestUser('Owner');
    $user->forceFill(['id' => 999]);
    expect(fn () => app(TaskLifecycle::class)->create(['title' => 'Forged'], $user))->toThrow(InvalidArgumentException::class);
    expect(Task::query()->count())->toBe(0);
});

it('rolls back a task when atomic same-connection activity cannot be recorded', function (): void {
    config()->set('nvl-activity.storage.table', 'missing_activity_table');

    expect(fn () => app(TaskLifecycle::class)->create(['title' => 'Rejected task'], SystemActor::Background))
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
        $task = app(TaskLifecycle::class)->create(['title' => 'Kept task'], SystemActor::Background);
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
        app(TaskLifecycle::class)->create(['title' => 'Rolled back'], SystemActor::Background);

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

    $task = app(TaskLifecycle::class)->create(['title' => 'Recoverable task'], SystemActor::Background);
    $pending = TaskActivityOutbox::query()->where('task_id', $task->id)->firstOrFail();
    createTaskActivityAuditTable();
    $pending->forceFill(['available_at' => CarbonImmutable::now()->subSecond()])->save();
    app(ActivityRecorder::class)->recordEnvelope(ActivityRecordEnvelope::fromArray($pending->payload));

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

    $task = app(TaskLifecycle::class)->create(['title' => 'Committed despite queue outage'], SystemActor::Background);
    $pending = TaskActivityOutbox::query()->where('task_id', $task->id)->firstOrFail();

    expect($task->exists)->toBeTrue()
        ->and($pending->delivered_at)->toBeNull()
        ->and($pending->attempts)->toBe(0);
});
