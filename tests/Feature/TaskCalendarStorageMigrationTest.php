<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Nvl\Support\Enums\SystemActor;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Services\TaskLifecycle;
use Nvl\Tasks\Services\TaskReader;

/**
 * @param  Closure(MySqlConnection, string, Migration): void  $callback
 */
function withLegacyTaskCalendarStorage(Closure $callback): void
{
    $name = 'task_calendar_'.bin2hex(random_bytes(6));
    $table = 'nvl_'.$name;
    $original = ['nvl-tasks.connection' => config('nvl-tasks.connection'), 'nvl-tasks.tables.tasks' => config('nvl-tasks.tables.tasks')];
    $configuration = DB::connection()->getConfig();
    $configuration['name'] = $name;
    $configuration['url'] = null;
    config(['database.connections.'.$name => $configuration, 'nvl-tasks.connection' => $name, 'nvl-tasks.tables.tasks' => $table]);
    $connection = DB::connection($name);

    try {
        if (! $connection instanceof MySqlConnection) {
            throw new RuntimeException('This legacy temporal regression requires a native MySQL-family source.');
        }
        $connection->getSchemaBuilder()->create($table, static function (Blueprint $blueprint): void {
            $blueprint->unsignedInteger('id')->primary();
            $blueprint->timestamp('target_at')->nullable();
            $blueprint->timestamp('due_at')->nullable();
        });
        $migration = require __DIR__.'/../../database/migrations/2026_11_01_123615_nvl_tasks_expand_calendar_datetime_range.php';
        $callback($connection, $table, $migration);
    } finally {
        $connection->getSchemaBuilder()->dropIfExists($table);
        DB::purge($name);
        config($original);
        config(['database.connections.'.$name => null]);
    }
}

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

it('widens the configured legacy calendar without shifting instants and refuses a lossy rollback', function (): void {
    if (! DB::connection() instanceof MySqlConnection) {
        $this->markTestSkipped('Native MySQL-family TIMESTAMP conversion is required.');
    }

    withLegacyTaskCalendarStorage(function (MySqlConnection $connection, string $table, Migration $migration): void {
        $connection->statement("SET SESSION time_zone = '+03:00'");
        $connection->table($table)->insert(['id' => 1, 'target_at' => null, 'due_at' => '2026-01-02 13:00:00']);
        $wrapped = $connection->getQueryGrammar()->wrapTable($table);
        $epoch = (int) $connection->selectOne('SELECT UNIX_TIMESTAMP(due_at) AS instant FROM '.$wrapped, [], false)->instant;

        $migration->up();
        $migration->up();
        $stored = $connection->table($table)->useWritePdo()->find(1);
        expect($migration->getConnection())->toBe($connection->getName())
            ->and($connection->selectOne('SELECT @@session.time_zone AS zone', [], false)->zone)->toBe('+03:00')
            ->and($connection->getSchemaBuilder()->getColumnType($table, 'target_at'))->toBe('datetime')
            ->and($connection->getSchemaBuilder()->getColumnType($table, 'due_at'))->toBe('datetime')
            ->and($stored->target_at)->toBeNull()
            ->and(CarbonImmutable::parse($stored->due_at, 'UTC')->getTimestamp())->toBe($epoch);

        $connection->table($table)->insert(['id' => 2, 'target_at' => '2200-01-01 00:00:00', 'due_at' => '2200-02-01 00:00:00']);
        expect(fn () => $migration->down())->toThrow(LogicException::class, 'Cannot narrow task calendar storage')
            ->and($connection->getSchemaBuilder()->getColumnType($table, 'target_at'))->toBe('datetime')
            ->and($connection->getSchemaBuilder()->getColumnType($table, 'due_at'))->toBe('datetime')
            ->and($connection->selectOne('SELECT @@session.time_zone AS zone', [], false)->zone)->toBe('+03:00');

        $connection->table($table)->where('id', 2)->delete();
        $migration->down();
        expect($connection->getSchemaBuilder()->getColumnType($table, 'due_at'))->toBe('timestamp')
            ->and((int) $connection->selectOne('SELECT UNIX_TIMESTAMP(due_at) AS instant FROM '.$wrapped, [], false)->instant)->toBe($epoch)
            ->and($connection->selectOne('SELECT @@session.time_zone AS zone', [], false)->zone)->toBe('+03:00');
    });
});
