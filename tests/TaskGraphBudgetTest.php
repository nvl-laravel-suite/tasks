<?php

declare(strict_types=1);

use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Nvl\Support\Enums\SystemActor;
use Nvl\Tasks\Events\TaskChanged;
use Nvl\Tasks\Models\TaskDependency;
use Nvl\Tasks\Models\TaskRelationship;
use Nvl\Tasks\Services\TaskGraphGuard;
use Nvl\Tasks\Services\TaskLifecycle;
use Nvl\Tasks\Services\TaskRelationships;
use Nvl\Tasks\Tests\CommittedTasksTestCase;

uses(CommittedTasksTestCase::class);

it('allows exact vertex and edge capacity while retaining idempotent link behavior', function (string $kind): void {
    $first = app(TaskLifecycle::class)->create(['title' => 'First'], SystemActor::Background);
    $second = app(TaskLifecycle::class)->create(['title' => 'Second'], SystemActor::Background);
    config(['nvl-tasks.limits.maximum_graph_vertices' => 2, 'nvl-tasks.limits.maximum_graph_edges' => 1]);
    $method = $kind === 'parent' ? 'linkParent' : 'addDependency';
    Event::fake([TaskChanged::class]);
    $link = app(TaskRelationships::class)->{$method}($first, $second, 1, 1, SystemActor::Background);
    $again = app(TaskRelationships::class)->{$method}($first, $second, 2, 2, SystemActor::Background);

    expect($again->id)->toBe($link->id)->and($first->fresh()->revision)->toBe(2)->and($second->fresh()->revision)->toBe(2);
    Event::assertDispatchedTimes(TaskChanged::class, 1);
})->with(['parent', 'dependency']);

it('bounds parent and dependency vertex traversal without revisions or events on denial', function (string $kind): void {
    $tasks = array_map(static fn (string $title) => app(TaskLifecycle::class)->create(['title' => $title], SystemActor::Background), ['A', 'B', 'C', 'D']);
    $relationships = app(TaskRelationships::class);
    $method = $kind === 'parent' ? 'linkParent' : 'addDependency';
    $relationships->{$method}($tasks[1], $tasks[2], 1, 1, SystemActor::Background);
    $relationships->{$method}($tasks[2], $tasks[3], 2, 1, SystemActor::Background);
    config(['nvl-tasks.limits.maximum_graph_vertices' => 3]);
    Event::fake([TaskChanged::class]);

    expect(fn () => $relationships->{$method}($tasks[0], $tasks[1], 1, 2, SystemActor::Background))->toThrow(InvalidArgumentException::class, 'budget')
        ->and($tasks[0]->fresh()->revision)->toBe(1)->and($tasks[1]->fresh()->revision)->toBe(2)
        ->and($kind === 'parent' ? TaskRelationship::query()->count() : TaskDependency::query()->count())->toBe(2);
    Event::assertNotDispatched(TaskChanged::class);
})->with(['parent', 'dependency']);

it('bounds wide dependency edges before loading an unbounded frontier', function (): void {
    $tasks = array_map(static fn (string $title) => app(TaskLifecycle::class)->create(['title' => $title], SystemActor::Background), ['Target', 'Blocker', 'C', 'D', 'E']);
    $relationships = app(TaskRelationships::class);
    foreach ([2, 3, 4] as $index) {
        $relationships->addDependency($tasks[1], $tasks[$index], $index - 1, 1, SystemActor::Background);
    }
    config(['nvl-tasks.limits.maximum_graph_edges' => 3]);
    Event::fake([TaskChanged::class]);

    expect(fn () => $relationships->addDependency($tasks[0], $tasks[1], 1, 4, SystemActor::Background))->toThrow(InvalidArgumentException::class, 'budget')
        ->and(TaskDependency::query()->count())->toBe(3)->and($tasks[0]->fresh()->revision)->toBe(1);
    Event::assertNotDispatched(TaskChanged::class);
});

it('refuses a graph cycle after an earlier host snapshot while another mutex writer commits', function (string $kind): void {
    $source = DB::connection();
    $driver = $source->getDriverName();
    if (! in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Repeatable-read graph evidence requires native PostgreSQL/MySQL/MariaDB connections.');
    }
    $tasks = array_map(static fn (string $title) => app(TaskLifecycle::class)->create(['title' => $title], SystemActor::Background), ['A', 'B', 'C', 'D']);
    $relationships = app(TaskRelationships::class);
    $method = $kind === 'parent' ? 'linkParent' : 'addDependency';
    $relationships->{$method}($tasks[1], $tasks[2], 1, 1, SystemActor::Background);
    $relationships->{$method}($tasks[3], $tasks[0], 1, 1, SystemActor::Background);
    config(['database.connections.graph_snapshot_writer' => $source->getConfig()]);
    $writer = DB::connection('graph_snapshot_writer');
    expect($writer->getPdo())->not->toBe($source->getPdo());
    $enforcedMariaSnapshot = false;
    if ($driver === 'mariadb') {
        $setting = $source->selectOne("SHOW SESSION VARIABLES LIKE 'innodb_snapshot_isolation'");
        $enforcedMariaSnapshot = in_array(strtoupper((string) ($setting->Value ?? '')), ['ON', '1'], true);
        $source->statement('SET SESSION innodb_lock_wait_timeout = 2');
        $writer->statement('SET SESSION innodb_lock_wait_timeout = 2');
    }
    if ($driver !== 'pgsql') {
        $source->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    }
    $source->beginTransaction();
    if ($driver === 'pgsql') {
        $source->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    }
    $model = $kind === 'parent' ? new TaskRelationship : new TaskDependency;
    $source->table($model->getTable())->count();
    try {
        $writer->transaction(static function () use ($writer, $model, $tasks, $kind): void {
            $default = DB::getDefaultConnection();
            $packageConnection = config('nvl-tasks.connection');
            DB::setDefaultConnection('graph_snapshot_writer');
            config(['nvl-tasks.connection' => 'graph_snapshot_writer']);
            try {
                app(TaskGraphGuard::class)->lockPair($tasks[2]->id, $tasks[3]->id, 2, 2, SystemActor::Background);
            } finally {
                DB::setDefaultConnection($default);
                config(['nvl-tasks.connection' => $packageConnection]);
            }
            $writer->table($model->getTable())->insert([
                'id' => (string) Str::uuid(),
                $kind === 'parent' ? 'child_task_id' : 'task_id' => $tasks[2]->id,
                $kind === 'parent' ? 'parent_task_id' : 'blocker_task_id' => $tasks[3]->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $writer->table($tasks[0]->getTable())->whereIn('id', [$tasks[2]->id, $tasks[3]->id])->increment('revision');
        });
        expect($writer->transactionLevel())->toBe(0)->and($writer->getPdo()->inTransaction())->toBeFalse();
        $denial = null;
        try {
            $relationships->{$method}($tasks[0]->id, $tasks[1]->id, 2, 2, SystemActor::Background);
        } catch (Throwable $error) {
            $denial = $error;
        }
        $staleSnapshot = $driver === 'pgsql' || $enforcedMariaSnapshot;
        $this->assertInstanceOf($staleSnapshot ? DeadlockException::class : InvalidArgumentException::class, $denial, $denial?->getMessage() ?? 'The graph mutation admitted a cycle.');
        if ($staleSnapshot) {
            $conflict = $denial->getPrevious();
            $this->assertInstanceOf(QueryException::class, $conflict);
            if ($driver === 'pgsql') {
                expect($conflict->errorInfo[0])->toBe('40001');
            } else {
                expect($conflict->errorInfo[0])->toBe('HY000')->and((int) $conflict->errorInfo[1])->toBe(1020)
                    ->and($conflict->getSql())->toContain($model->getTable(), 'for update');
            }
        } else {
            expect($denial->getMessage())->toContain('cycle');
        }
    } finally {
        while ($source->transactionLevel() > 0) {
            $source->rollBack();
        }
        DB::purge('graph_snapshot_writer');
    }
    expect($source->table($model->getTable())->count())->toBe(3)
        ->and($tasks[0]->fresh()->revision)->toBe(2)->and($tasks[1]->fresh()->revision)->toBe(2);
})->with(['parent', 'dependency']);
