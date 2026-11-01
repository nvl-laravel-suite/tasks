<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Tasks\Definitions\Tables\TasksTables;
use Nvl\Tasks\Support\TasksConfiguration;

return new class extends Migration
{
    /** Use the configured Task model's actual connection. */
    public function getConnection(): ?string
    {
        return TasksConfiguration::connection();
    }

    /** Add a ready/delivered index without rewriting historical storage. */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $table = TasksConfiguration::table(TasksTables::ActivityOutbox);
        foreach ($schema->getIndexes($table) as $index) {
            if ($index['name'] === 'nvl_task_activity_outbox_delivery_idx') {
                if ($index['columns'] !== ['delivered_at', 'available_at', 'id']) {
                    throw new LogicException('The Task activity delivery index name is occupied by another definition.');
                }

                return;
            }
        }
        $schema->table($table, static fn (Blueprint $table) => $table->index(['delivered_at', 'available_at', 'id'], 'nvl_task_activity_outbox_delivery_idx'));
    }

    /** Remove only the additive performance index; retain all delivery evidence. */
    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $table = TasksConfiguration::table(TasksTables::ActivityOutbox);
        if ($schema->hasIndex($table, 'nvl_task_activity_outbox_delivery_idx')) {
            $schema->table($table, static fn (Blueprint $table) => $table->dropIndex('nvl_task_activity_outbox_delivery_idx'));
        }
    }
};
