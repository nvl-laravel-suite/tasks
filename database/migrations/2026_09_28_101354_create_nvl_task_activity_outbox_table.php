<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Tasks\Definitions\Tables\TasksTables;
use Nvl\Tasks\Support\TasksConfiguration;

return new class extends Migration
{
    /** Create durable activity envelopes independently from task deletion. */
    public function up(): void
    {
        $schema = Schema::connection(TasksConfiguration::connection());
        $name = TasksConfiguration::table(TasksTables::ActivityOutbox);

        if ($schema->hasTable($name)) {
            throw new LogicException("Task activity outbox table [{$name}] already exists; disable tasks.migrations.enabled during controlled schema adoption.");
        }

        $schema->create($name, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id');
            $table->uuid('tenant_id')->nullable();
            $table->json('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->uuid('lease_token')->nullable();
            $table->timestamp('leased_until')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index('task_id', 'nvl_task_activity_outbox_task_idx');
            $table->index(['tenant_id', 'delivered_at', 'available_at'], 'nvl_task_activity_outbox_ready_idx');
            $table->index(['leased_until', 'lease_token'], 'nvl_task_activity_outbox_lease_idx');
        });
    }

    /** Remove the activity outbox. */
    public function down(): void
    {
        Schema::connection(TasksConfiguration::connection())
            ->dropIfExists(TasksConfiguration::table(TasksTables::ActivityOutbox));
    }
};
