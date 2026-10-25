<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Ownership\Contracts\OwnerSystemAccess;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Contracts\OwnershipQueueContext;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use Nvl\Tasks\Events\TaskChanged;
use Nvl\Tasks\Jobs\ProcessTaskActivityOutboxJob;
use Nvl\Tasks\Models\TaskActivityOutbox;
use Nvl\Tasks\Services\TaskLifecycle;
use Nvl\Tasks\Services\TasksActivityDelivery;
use Nvl\Tasks\Services\TasksActivityDrainDispatcher;
use Nvl\Tasks\Tests\ActivityOwnerScopeTestCase;

uses(ActivityOwnerScopeTestCase::class);

it('commits exactly one owned audit fact and preserves immutable outbox replay', function (): void {
    $actor = Actor::query()->findOrFail(1);
    $runner = app(OwnershipRunner::class);
    $id = $runner->run(Company::query()->findOrFail(7), $actor, function (): string {
        app(TaskLifecycle::class)->create(['title' => 'Audit'], SystemActor::Background);
        $record = TaskActivityOutbox::query()->sole();
        expect(ActivityLog::query()->count())->toBe(1)
            ->and($record->delivered_at)->not->toBeNull()
            ->and(app(TasksActivityDelivery::class)->deliver($record->id))->toBeTrue();
        $connection = $record->getConnection();
        expect(fn () => $connection->table($record->getTable())->where('id', $record->id)->update(['payload' => '{}']))->toThrow(QueryException::class);

        return $record->id;
    });
    $runner->run(Company::query()->findOrFail(8), $actor, function () use ($id): void {
        expect(TaskActivityOutbox::query()->count())->toBe(0)
            ->and(ActivityLog::query()->count())->toBe(0)
            ->and(app(TasksActivityDelivery::class)->deliver($id))->toBeFalse();
    });
});

it('captures the committed task event and outbox job before later ambient owner changes', function (): void {
    Event::fake([TaskChanged::class]);
    $actor = Actor::query()->findOrFail(1);
    $job = app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), $actor, function (): ProcessTaskActivityOutboxJob {
        app(TaskLifecycle::class)->create(['title' => 'Capture'], SystemActor::Background);
        Event::assertDispatched(TaskChanged::class, function (TaskChanged $event): bool {
            expect($event)->toBeInstanceOf(OwnershipQueuedJob::class);
            expect($event->ownershipJobEnvelope()->context()->owner?->key)->toBe('7');

            return true;
        });

        return new ProcessTaskActivityOutboxJob(TaskActivityOutbox::query()->sole()->id,
            ownership: OwnershipJobEnvelope::capture(app(OwnershipContext::class)));
    });
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(8), $actor, function () use ($job): void {
        app(OwnershipQueueContext::class)->run($job->ownershipJobEnvelope(), function () use ($job): void {
            expect(app(OwnershipContext::class)->snapshot()->owner?->key)->toBe('7');
            $job->handle(app(TasksActivityDelivery::class));
        });
        expect(app(OwnershipContext::class)->snapshot()->owner?->key)->toBe('8');
    });
});

it('rolls back both the audit and captured outbox when a task source transaction fails', function (): void {
    Event::fake([TaskChanged::class]);
    $actor = Actor::query()->findOrFail(1);
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), $actor, function (): void {
        $connection = (new TaskActivityOutbox)->getConnection();
        expect(fn () => $connection->transaction(function (): void {
            app(TaskLifecycle::class)->create(['title' => 'Rolled back'], SystemActor::Background);
            throw new RuntimeException('rollback');
        }))->toThrow(RuntimeException::class, 'rollback');
        expect(TaskActivityOutbox::query()->count())->toBe(0)->and(ActivityLog::query()->count())->toBe(0);
        Event::assertNothingDispatched();
    });
});

it('recovers durable activity one authorized native owner at a time without a tenant directory', function (): void {
    $actor = Actor::query()->findOrFail(1);
    foreach ([7, 8] as $key) {
        app(OwnershipRunner::class)->run(Company::query()->findOrFail($key), $actor, function (): void {
            app(TaskLifecycle::class)->create(['title' => 'Recovery'], SystemActor::Background);
            $outbox = TaskActivityOutbox::query()->sole();
            $outbox->forceFill(['delivered_at' => null])->save();
        });
    }
    expect(fn () => app(TasksActivityDrainDispatcher::class)->drain(10))
        ->toThrow(OwnershipViolation::class);
    config(['nvl-ownership.system_operations' => ['tasks.activity-drain']]);
    app()->bind(OwnerSystemAccess::class, fn () => new class implements OwnerSystemAccess
    {
        public function assertCanRun(string $operation, Model $owner): void
        {
            expect($operation)->toBe('tasks.activity-drain');
        }
    });
    expect(app(TasksActivityDrainDispatcher::class)->drain(10))->toBe(2);
    foreach ([7, 8] as $key) {
        app(OwnershipRunner::class)->run(Company::query()->findOrFail($key), $actor, function (): void {
            expect(TaskActivityOutbox::query()->sole()->delivered_at)->not->toBeNull()
                ->and(ActivityLog::query()->count())->toBe(1);
        });
    }
    expect(app(OwnershipContext::class)->snapshot()->owner)->toBeNull();
});
