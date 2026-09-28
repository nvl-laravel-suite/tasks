<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Nvl\Tasks\Definitions\Tables\TasksTables;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskActivityOutbox;
use Nvl\Tasks\Support\TasksConfiguration;

it('retains a task activity payload after its task is hard deleted', function (): void {
    $task = new Task;
    $task->forceFill(['title' => 'Durable activity'])->save();

    $outbox = new TaskActivityOutbox;
    $outbox->forceFill([
        'task_id' => $task->id,
        'payload' => ['event' => 'task.created', 'task_id' => $task->id],
        'available_at' => now(),
    ])->save();

    $task->forceDelete();

    expect($outbox->fresh()?->payload)->toBe(['event' => 'task.created', 'task_id' => $task->id])
        ->and($outbox->fresh()?->attempts)->toBe(0)
        ->and(DB::connection(TasksConfiguration::connection())
            ->table(TasksConfiguration::table(TasksTables::ActivityOutbox))
            ->where('id', $outbox->id)->exists())->toBeTrue();

    expect(fn () => $outbox->forceFill(['payload' => ['event' => 'rewritten']])->save())
        ->toThrow(LogicException::class);
});

it('reports the outbox table and required columns in Tasks Doctor', function (): void {
    expect(Artisan::call('nvl:tasks:doctor', ['--format' => 'json']))->toBe(0);

    /** @var array{checks: array<string, bool>} $result */
    $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($result['checks']['activity_outbox.table'])->toBeTrue()
        ->and($result['checks']['activity_outbox.columns'])->toBeTrue();
});

it('reports a missing tenant recovery worklist before scheduled delivery is enabled', function (): void {
    config()->set('tenancy.enabled', true);
    config()->set('activity.tenancy.active_tenant_worklist', []);

    expect(Artisan::call('nvl:tasks:doctor', ['--format' => 'json']))->toBe(0);

    /** @var array{checks: array<string, bool>} $result */
    $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($result['checks']['activity_outbox.recovery_worklist'])->toBeFalse();

    config()->set('activity.tenancy.active_tenant_worklist', ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']);
    Artisan::call('nvl:tasks:doctor', ['--format' => 'json']);

    /** @var array{checks: array<string, bool>} $result */
    $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($result['checks']['activity_outbox.recovery_worklist'])->toBeTrue();
});
