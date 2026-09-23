<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Tasks\Definitions\Tables\TasksTables;
use Nvl\Tasks\Support\TasksConfiguration;

return new class extends Migration
{
    /** Create the many-assignee relation without imposing a host user model. */
    public function up(): void
    {
        $schema = Schema::connection(TasksConfiguration::connection());
        $name = TasksConfiguration::table(TasksTables::Assignments);

        if ($schema->hasTable($name)) {
            throw new LogicException("Task assignments table [{$name}] already exists; disable tasks.migrations.enabled during controlled schema adoption.");
        }

        $schema->create($name, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id');
            $table->uuid('tenant_id')->nullable();
            $table->string('assignee_type', 191);
            $table->string('assignee_id', 191);
            $table->string('assigned_by_type', 191)->nullable();
            $table->string('assigned_by_id', 191)->nullable();
            $table->timestamps();

            $table->foreign('task_id')->references('id')
                ->on(TasksConfiguration::table(TasksTables::Tasks))->cascadeOnDelete();
            $table->unique(['task_id', 'assignee_type', 'assignee_id'], 'nvl_task_assignments_unique');
            $table->index(['tenant_id', 'assignee_type', 'assignee_id'], 'nvl_task_assignments_lookup_idx');
        });
    }

    /** Drop task assignments before their parent tasks. */
    public function down(): void
    {
        Schema::connection(TasksConfiguration::connection())
            ->dropIfExists(TasksConfiguration::table(TasksTables::Assignments));
    }
};
