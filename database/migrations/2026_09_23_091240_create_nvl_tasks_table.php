<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Tasks\Definitions\Tables\TasksTables;
use Nvl\Tasks\Support\TasksConfiguration;

return new class extends Migration
{
    /** Create the task records owned by this package. */
    public function up(): void
    {
        $schema = Schema::connection(TasksConfiguration::connection());
        $name = TasksConfiguration::table(TasksTables::Tasks);

        if ($schema->hasTable($name)) {
            throw new LogicException("Tasks table [{$name}] already exists; disable tasks.migrations.enabled during controlled schema adoption.");
        }

        $schema->create($name, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->string('creator_type', 191)->nullable();
            $table->string('creator_id', 191)->nullable();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('status', 32)->default('open');
            $table->string('priority', 32)->default('normal');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status', 'due_at'], 'nvl_tasks_tenant_status_due_idx');
            $table->index(['tenant_id', 'updated_at'], 'nvl_tasks_tenant_updated_idx');
            $table->index(['creator_type', 'creator_id'], 'nvl_tasks_creator_idx');
        });
    }

    /** Drop the task records owned by this package. */
    public function down(): void
    {
        Schema::connection(TasksConfiguration::connection())
            ->dropIfExists(TasksConfiguration::table(TasksTables::Tasks));
    }
};
