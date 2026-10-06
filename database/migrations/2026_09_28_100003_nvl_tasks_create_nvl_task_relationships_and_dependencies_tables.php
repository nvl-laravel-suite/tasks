<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Support\Config\PackageStorage;
use Nvl\Tasks\Definitions\Tables\TasksTables;
use Nvl\Tasks\Support\TasksConfiguration;

return new class extends Migration
{
    /** Use the effective package connection for Laravel's migration transaction. */
    public function getConnection(): ?string
    {
        return PackageStorage::connection('tasks');
    }

    /** Create the package-owned parent and blocker graphs. */
    public function up(): void
    {
        $schema = Schema::connection(TasksConfiguration::connection());
        $relationships = TasksConfiguration::table(TasksTables::get(TasksTables::Relationships));
        $dependencies = TasksConfiguration::table(TasksTables::get(TasksTables::Dependencies));
        $tasks = TasksConfiguration::table(TasksTables::get(TasksTables::Tasks));

        if ($schema->hasTable($relationships) || $schema->hasTable($dependencies)) {
            throw new LogicException('Task relationship tables already exist; disable tasks.migrations.enabled during controlled schema adoption.');
        }

        $schema->create($relationships, function (Blueprint $table) use ($tasks): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('child_task_id');
            $table->uuid('parent_task_id');
            $table->timestamps();

            $table->foreign('child_task_id')->references('id')->on($tasks)->cascadeOnDelete();
            $table->foreign('parent_task_id')->references('id')->on($tasks)->cascadeOnDelete();
            $table->unique('child_task_id', 'nvl_task_relationships_child_unique');
            $table->index('parent_task_id', 'nvl_task_relationships_parent_idx');
        });

        $schema->create($dependencies, function (Blueprint $table) use ($tasks): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('task_id');
            $table->uuid('blocker_task_id');
            $table->timestamps();

            $table->foreign('task_id')->references('id')->on($tasks)->cascadeOnDelete();
            $table->foreign('blocker_task_id')->references('id')->on($tasks)->cascadeOnDelete();
            $table->unique(['task_id', 'blocker_task_id'], 'nvl_task_dependencies_pair_unique');
            $table->index('blocker_task_id', 'nvl_task_dependencies_blocker_idx');
        });
    }

    /** Drop blocker edges before parent edges. */
    public function down(): void
    {
        $schema = Schema::connection(TasksConfiguration::connection());
        $schema->dropIfExists(TasksConfiguration::table(TasksTables::get(TasksTables::Dependencies)));
        $schema->dropIfExists(TasksConfiguration::table(TasksTables::get(TasksTables::Relationships)));
    }
};
