<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Tasks\Definitions\Tables\TasksTables;
use Nvl\Tasks\Support\TasksConfiguration;

return new class extends Migration
{
    /** Create task-owned labels with one normalized value per task. */
    public function up(): void
    {
        $schema = Schema::connection(TasksConfiguration::connection());
        $name = TasksConfiguration::table(TasksTables::Tags);

        if ($schema->hasTable($name)) {
            throw new LogicException("Task tags table [{$name}] already exists; disable tasks.migrations.enabled during controlled schema adoption.");
        }

        $schema->create($name, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id');
            $table->uuid('tenant_id')->nullable();
            $table->string('tag', 64);
            $table->timestamps();

            $table->foreign('task_id')->references('id')
                ->on(TasksConfiguration::table(TasksTables::Tasks))->cascadeOnDelete();
            $table->unique(['task_id', 'tag'], 'nvl_task_tags_unique');
            $table->index(['tenant_id', 'task_id'], 'nvl_task_tags_tenant_task_idx');
        });
    }

    /** Drop task labels after their parent task is no longer present. */
    public function down(): void
    {
        Schema::connection(TasksConfiguration::connection())
            ->dropIfExists(TasksConfiguration::table(TasksTables::Tags));
    }
};
