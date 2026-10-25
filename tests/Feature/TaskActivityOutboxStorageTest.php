<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Nvl\Support\Enums\SystemActor;
use Nvl\Tasks\Definitions\Tables\TasksTables;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskActivityOutbox;
use Nvl\Tasks\Services\TaskLifecycle;
use Nvl\Tasks\Services\TasksActivityDrainDispatcher;
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

it('recovers task facts without a legacy tenant worklist', function (): void {
    config()->set('nvl-tenancy.enabled', true);
    $task = app(TaskLifecycle::class)->create(['title' => 'Native recovery'], SystemActor::Background);
    $outbox = TaskActivityOutbox::query()->where('task_id', $task->id)->firstOrFail();
    $outbox->forceFill(['delivered_at' => null])->save();
    expect(app(TasksActivityDrainDispatcher::class)->drain(10))->toBe(1);
    expect($outbox->fresh()->delivered_at)->not->toBeNull();
});
