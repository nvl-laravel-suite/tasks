<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Nvl\Tasks\Services\CachedTaskSchedulerReadiness;
use Nvl\Tasks\Services\TaskSchedulerDiagnostics;

it('requires observed scheduler execution rather than registered events alone', function (): void {
    $checks = collect(app(TaskSchedulerDiagnostics::class)->inspect())->keyBy('key');
    expect($checks['scheduler.running']['passed'])->toBeFalse()
        ->and($checks['scheduler.running']['severity'])->toBe('warning')
        ->and($checks['scheduler.atomic_locks']['passed'])->toBeTrue();
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains($event->command ?? '', 'nvl:tasks:activity:drain'));
    expect($event)->not->toBeNull();
    $event->callBeforeCallbacks(app());
    expect(app(CachedTaskSchedulerReadiness::class)->running())->toBeTrue();
    $this->travel(181)->seconds();
    expect(app(CachedTaskSchedulerReadiness::class)->running())->toBeFalse();
});

it('does not require scheduler readiness for an explicitly disabled correctness schedule', function (): void {
    config(['nvl-tasks.activity.schedule.enabled' => false]);
    expect(app(TaskSchedulerDiagnostics::class)->inspect())->toHaveCount(1);
    expect(app(TaskSchedulerDiagnostics::class)->inspect()[0]['severity'])->toBe('info');
});

it('fails scheduler diagnostics when its effective cache cannot initialize atomic locks', function (): void {
    config(['cache.default' => 'missing_scheduler_cache']);
    $checks = collect(app(TaskSchedulerDiagnostics::class)->inspect())->keyBy('key');
    expect($checks['scheduler.atomic_locks']['passed'])->toBeFalse()
        ->and($checks['scheduler.running']['passed'])->toBeFalse();
});
