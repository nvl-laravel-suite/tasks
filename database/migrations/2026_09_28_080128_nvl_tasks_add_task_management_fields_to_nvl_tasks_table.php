<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Support\Config\PackageStorage;
use Nvl\Tasks\Definitions\Tables\TasksTables;
use Nvl\Tasks\Support\TaskEnumConfiguration;
use Nvl\Tasks\Support\TasksConfiguration;

return new class extends Migration
{
    /** Use the effective package connection for Laravel's migration transaction. */
    public function getConnection(): ?string
    {
        return PackageStorage::connection('tasks');
    }

    /** Add reusable task classification, planning, and effort fields. */
    public function up(): void
    {
        Schema::connection(TasksConfiguration::connection())->table(TasksConfiguration::table(TasksTables::get(TasksTables::Tasks)), function (Blueprint $table): void {
            $table->string('type', 64)->default(TaskEnumConfiguration::defaultValue('type'));
            $table->string('category', 64)->default(TaskEnumConfiguration::defaultValue('category'));
            $table->string('importance', 32)->default(TaskEnumConfiguration::defaultValue('importance'));
            $table->timestamp('target_at')->nullable();
            $table->unsignedInteger('estimated_seconds')->nullable();

            $table->index(['tenant_id', 'type', 'status'], 'nvl_tasks_tenant_type_status_idx');
            $table->index(['tenant_id', 'category', 'updated_at'], 'nvl_tasks_tenant_category_updated_idx');
        });
    }

    /** Remove the task management expansion. */
    public function down(): void
    {
        Schema::connection(TasksConfiguration::connection())->table(TasksConfiguration::table(TasksTables::get(TasksTables::Tasks)), function (Blueprint $table): void {
            $table->dropIndex('nvl_tasks_tenant_type_status_idx');
            $table->dropIndex('nvl_tasks_tenant_category_updated_idx');
            $table->dropColumn(['type', 'category', 'importance', 'target_at', 'estimated_seconds']);
        });
    }
};
