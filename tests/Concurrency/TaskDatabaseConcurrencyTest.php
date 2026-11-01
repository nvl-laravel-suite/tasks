<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Nvl\Tasks\Exceptions\TaskRevisionConflict;
use Nvl\Tasks\Models\TaskDependency;
use Nvl\Tasks\Models\TaskRelationship;
use Nvl\Tasks\Models\TaskTimeEntry;
use Nvl\Tasks\Services\TaskLifecycle;
use Nvl\Tasks\Services\TaskRelationships;
use Nvl\Tasks\Services\TaskTimeTracking;
use Nvl\Tasks\Tests\ConcurrencyTestCase;

uses(ConcurrencyTestCase::class);

beforeEach(function (): void {
    if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Real Tasks races require the native PostgreSQL/MySQL/MariaDB database runner.');
    }
    if (! function_exists('pcntl_fork') || ! function_exists('pcntl_exec')) {
        $this->markTestSkipped('Real Tasks races require pcntl worker processes.');
    }
});

/**
 * Start two independent database processes at a common barrier, with bounded failure handling.
 *
 * @param  list<Closure(int, string): void>  $workers
 * @return list<array{ok: bool, error?: string, message?: string}>
 */
function tasksRace(array $workers): array
{
    $directory = sys_get_temp_dir().'/nvl-tasks-race-'.bin2hex(random_bytes(8));
    mkdir($directory);
    $connection = DB::getDefaultConnection();
    $children = [];
    try {
        foreach ($workers as $index => $worker) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Could not fork task race worker.');
            }
            if ($pid > 0) {
                $children[] = $pid;

                continue;
            }
            DB::purge($connection);
            try {
                file_put_contents($directory.'/ready-'.$index, 'ready');
                $deadline = microtime(true) + 8;
                while (! is_file($directory.'/go')) {
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException('Task race start barrier timed out.');
                    }
                    usleep(1000);
                }
                $worker($index, $directory);
                $result = ['ok' => true];
            } catch (Throwable $error) {
                $result = ['ok' => false, 'error' => $error::class, 'message' => $error->getMessage()];
            }
            file_put_contents($directory.'/result-'.$index, json_encode($result, JSON_THROW_ON_ERROR));
            pcntl_exec('/usr/bin/true');
            exit(1);
        }
        $deadline = microtime(true) + 8;
        while (count(glob($directory.'/ready-*')) !== count($workers)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Task race workers failed to reach the start barrier.');
            }
            usleep(1000);
        }
        file_put_contents($directory.'/go', 'go');
        foreach ($children as $pid) {
            $deadline = microtime(true) + 12;
            while (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Task race worker failed to finish within 12 seconds.');
                }
                usleep(1000);
            }
            expect(pcntl_wifexited($status))->toBeTrue()->and(pcntl_wexitstatus($status))->toBe(0);
        }
        DB::purge($connection);

        return array_map(fn (int $index): array => json_decode(file_get_contents($directory.'/result-'.$index), true, flags: JSON_THROW_ON_ERROR), array_keys($workers));
    } finally {
        foreach ($children as $pid) {
            if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
        }
        foreach (glob($directory.'/*') as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
}

it('serializes simultaneous native timer starts at one revision', function (): void {
    $actor = nativeTaskUser();
    $task = app(TaskLifecycle::class)->create(['title' => 'Concurrent timer'], $actor);
    $worker = fn () => app(TaskTimeTracking::class)->startTimer($task->id, 1, User::query()->findOrFail($actor->id));
    $results = tasksRace([$worker, $worker]);
    expect(array_column($results, 'ok'))->toContain(true, false)
        ->and(array_column($results, 'error'))->toBe([TaskRevisionConflict::class])
        ->and(TaskTimeEntry::query()->whereNull('stopped_at')->count())->toBe(1)
        ->and($task->fresh()->revision)->toBe(2);
});

it('prevents disjoint concurrent native graph edits from closing a cycle', function (string $kind): void {
    $actor = nativeTaskUser();
    $tasks = array_map(fn (string $title) => app(TaskLifecycle::class)->create(['title' => $title], $actor), ['A', 'B', 'C', 'D']);
    $method = $kind === 'parent' ? 'linkParent' : 'addDependency';
    app(TaskRelationships::class)->{$method}($tasks[0], $tasks[1], 1, 1, $actor);
    app(TaskRelationships::class)->{$method}($tasks[2], $tasks[3], 1, 1, $actor);
    $workers = [];
    foreach ([[1, 2, 3], [3, 0, 1]] as [$from, $to, $terminal]) {
        $workers[] = function (int $index, string $directory) use ($tasks, $actor, $method, $kind, $from, $to, $terminal): void {
            $paused = false;
            DB::listen(function (QueryExecuted $query) use (&$paused, $tasks, $terminal, $kind, $directory, $index): void {
                $table = $kind === 'parent' ? 'relationships' : 'dependencies';
                if ($paused || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, $table) || ! in_array($tasks[$terminal]->id, $query->bindings, true)) {
                    return;
                }
                $paused = true;
                file_put_contents($directory.'/cycle-'.$index, 'read');
                $deadline = microtime(true) + 1;
                while (! is_file($directory.'/cycle-'.(1 - $index)) && microtime(true) < $deadline) {
                    usleep(1000);
                }
            });
            app(TaskRelationships::class)->{$method}($tasks[$from]->id, $tasks[$to]->id, 2, 2, User::query()->findOrFail($actor->id));
        };
    }
    $results = tasksRace($workers);
    expect(array_column($results, 'ok'))->toContain(true, false)
        ->and(array_column($results, 'error'))->toBe([InvalidArgumentException::class]);
    expect($kind === 'parent' ? TaskRelationship::query()->count() : TaskDependency::query()->count())->toBe(3);
})->with(['parent', 'dependency']);
