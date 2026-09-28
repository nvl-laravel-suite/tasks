<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Tasks\Definitions\Tables\TasksTables;
use Nvl\Tasks\Support\TasksConfiguration;

return new class extends Migration
{
    /** Create task-owned time intervals and a unique running timer per performer. */
    public function up(): void
    {
        $schema = Schema::connection(TasksConfiguration::connection());
        $name = TasksConfiguration::table(TasksTables::TimeEntries);
        $connection = DB::connection(TasksConfiguration::connection());
        $driver = $connection->getDriverName();

        if ($schema->hasTable($name)) {
            throw new LogicException("Task time entries table [{$name}] already exists; disable tasks.migrations.enabled during controlled schema adoption.");
        }

        $schema->create($name, function (Blueprint $table) use ($driver): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id');
            $table->uuid('tenant_id')->nullable();
            $table->string('performer_type', 191);
            $table->string('performer_id', 191);
            $table->timestamp('started_at');
            $table->timestamp('stopped_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('description', 2000)->nullable();
            $table->timestamps();

            $table->foreign('task_id')->references('id')
                ->on(TasksConfiguration::table(TasksTables::Tasks))->cascadeOnDelete();
            $table->index(['tenant_id', 'task_id', 'started_at'], 'nvl_task_time_entries_lookup_idx');

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $table->unsignedTinyInteger('running_slot')
                    ->storedAs('CASE WHEN stopped_at IS NULL THEN 1 ELSE NULL END');
                $table->unique(
                    ['task_id', 'performer_type', 'performer_id', 'running_slot'],
                    'nvl_task_time_entries_running_unique',
                );
            }
        });

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $table = $connection->getQueryGrammar()->wrapTable($name);
            $connection->statement(
                "CREATE UNIQUE INDEX nvl_task_time_entries_running_unique ON {$table} (task_id, performer_type, performer_id) WHERE stopped_at IS NULL"
            );
        }
    }

    /** Remove time entries and their running-timer index. */
    public function down(): void
    {
        Schema::connection(TasksConfiguration::connection())
            ->dropIfExists(TasksConfiguration::table(TasksTables::TimeEntries));
    }
};
