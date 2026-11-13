<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Nvl\Support\Enums\SystemActor;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Services\TaskLifecycle;
use Nvl\Tasks\Services\TaskReader;

it('persists and searches target and due dates beyond the timestamp horizon', function (): void {
    $task = app(TaskLifecycle::class)->create(['title' => 'Long horizon', 'target_at' => '2098-01-01', 'due_at' => '2099-01-01'], SystemActor::Background);

    expect($task->fresh()->target_at?->format('Y-m-d'))->toBe('2098-01-01')
        ->and($task->fresh()->due_at?->format('Y-m-d'))->toBe('2099-01-01')
        ->and(app(TaskReader::class)->paginate(['target_from' => '2098-01-01', 'due_to' => '2099-01-01'], SystemActor::Background)->total())->toBe(1);
});

it('rejects calendar instants outside portable datetime storage before writing', function (string $field, int $year): void {
    expect(fn () => app(TaskLifecycle::class)->create(['title' => 'Outside storage', $field => CarbonImmutable::create($year, 1, 1, 0, 0, 0, 'UTC')], SystemActor::Background))
        ->toThrow(ValidationException::class)
        ->and(Task::query()->count())->toBe(0);
})->with(['target_at', 'due_at'])->with([999, 10_000]);
