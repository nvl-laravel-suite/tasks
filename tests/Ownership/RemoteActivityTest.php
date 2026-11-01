<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\Fixtures\NativeDatabaseSource;
use Nvl\Ownership\Tests\Fixtures\Queue\QueueScenario;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Tasks\Jobs\ProcessTaskActivityOutboxJob;
use Nvl\Tasks\Models\TaskActivityOutbox;
use Nvl\Tasks\Services\TaskLifecycle;
use Nvl\Tasks\Services\TasksActivityDelivery;
use Nvl\Tasks\Tests\RemoteActivityOwnerScopeTestCase;
use Symfony\Component\Process\Process;

uses(RemoteActivityOwnerScopeTestCase::class);

beforeEach(fn () => QueueScenario::install());

it('captures and retries remote audit delivery without recapturing the serialization owner', function (): void {
    $actor = Actor::query()->findOrFail(1);
    $job = app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), $actor, function (): ProcessTaskActivityOutboxJob {
        app(TaskLifecycle::class)->create(['title' => 'Remote'], SystemActor::Background);
        $payload = json_decode(DB::table('jobs')->sole()->payload, true, flags: JSON_THROW_ON_ERROR);
        expect($payload['data']['nvl_ownership']['owner']['key'])->toBe('7')
            ->and(ActivityLog::query()->count())->toBe(0);

        return unserialize($payload['data']['command']);
    });
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(8), $actor, function () use ($job): void {
        expect($job->ownershipJobEnvelope()->context()->owner?->key)->toBe('7');
        expect(app(TasksActivityDelivery::class)->deliver($job->eventId))->toBeFalse();
    });
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), $actor, function () use ($job): void {
        expect(app(TasksActivityDelivery::class)->deliver($job->eventId))->toBeTrue()
            ->and(app(TasksActivityDelivery::class)->deliver($job->eventId))->toBeTrue()
            ->and(ActivityLog::query()->count())->toBe(1)
            ->and(TaskActivityOutbox::query()->sole()->attempts)->toBe(1);
    });
});

it('delivers captured task outboxes through an independent worker and quarantines foreign scope metadata', function (): void {
    if (! in_array(getenv('NVL_OWNERSHIP_TEST_DRIVER'), ['pgsql', 'mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Requires explicitly dedicated native sources and a real worker.');
    }
    $actor = Actor::query()->findOrFail(1);
    foreach ([7, 8] as $key) {
        app(OwnershipRunner::class)->run(Company::query()->findOrFail($key), $actor, function (): void {
            $task = app(TaskLifecycle::class)->create(['title' => 'Worker'], SystemActor::Background);
            $task->forceDelete();
        });
    }
    $payload = json_decode(DB::table('jobs')->orderBy('id')->first()->payload, true, flags: JSON_THROW_ON_ERROR);
    $payload['uuid'] = (string) Str::uuid();
    $payload['data']['nvl_ownership']['owner']['key'] = '8';
    Queue::connection('database')->pushRaw(json_encode($payload, JSON_THROW_ON_ERROR));
    $worker = new Process([PHP_BINARY, __DIR__.'/../Fixtures/Ownership/worker.php', json_encode([
        'primary' => NativeDatabaseSource::capture(DB::connection('sqlite')), 'remote' => NativeDatabaseSource::capture(DB::connection('ownership_remote')),
    ], JSON_THROW_ON_ERROR)], timeout: 40);
    $worker->mustRun();
    expect($worker->getOutput())->toBe('tasks-worker-complete')
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('ownership_queue_probes')->where('owner', '<>', 'unresolved')->count())->toBe(0);
    foreach ([7, 8] as $key) {
        app(OwnershipRunner::class)->run(Company::query()->findOrFail($key), $actor, function (): void {
            expect(ActivityLog::query()->count())->toBe(1)
                ->and(TaskActivityOutbox::query()->sole()->delivered_at)->not->toBeNull();
        });
    }
    expect(app(OwnershipContext::class)->snapshot()->owner)->toBeNull();
});
