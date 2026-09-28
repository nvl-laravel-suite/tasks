<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Tasks\Definitions\Tables\TasksTables;
use Nvl\Tasks\Support\TasksConfiguration;

return new class extends Migration
{
    /** Create ordered checklist rows owned by tasks. */
    public function up(): void
    {
        $schema = Schema::connection(TasksConfiguration::connection());
        $name = TasksConfiguration::table(TasksTables::ChecklistItems);

        if ($schema->hasTable($name)) {
            throw new LogicException("Task checklist items table [{$name}] already exists; disable tasks.migrations.enabled during controlled schema adoption.");
        }

        $schema->create($name, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id');
            $table->uuid('tenant_id')->nullable();
            $table->string('title', 255);
            $table->unsignedInteger('position');
            $table->timestamp('completed_at')->nullable();
            $table->string('completed_by_type', 191)->nullable();
            $table->string('completed_by_id', 191)->nullable();
            $table->timestamps();

            $table->foreign('task_id')->references('id')
                ->on(TasksConfiguration::table(TasksTables::Tasks))->cascadeOnDelete();
            $table->index(['task_id', 'position'], 'nvl_task_checklist_order_idx');
            $table->index(['tenant_id', 'task_id'], 'nvl_task_checklist_tenant_idx');
        });
    }

    /** Drop checklist rows before their parent tasks. */
    public function down(): void
    {
        Schema::connection(TasksConfiguration::connection())
            ->dropIfExists(TasksConfiguration::table(TasksTables::ChecklistItems));
    }
};
