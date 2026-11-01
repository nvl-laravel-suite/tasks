<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\Support\Config\PackageStorage;
use Nvl\Tasks\Definitions\Tables\TasksTables;
use Nvl\Tasks\Support\TasksConfiguration;

return new class extends Migration
{
    /** Use the actual configured task source for schema and rollback preflight. */
    public function getConnection(): ?string
    {
        return PackageStorage::connection('tasks');
    }

    /** Expand calendar storage beyond MySQL-family TIMESTAMP horizons. */
    public function up(): void
    {
        $this->resize(true);
    }

    /** Refuse to narrow either calendar column if any accepted instant would be lost. */
    public function down(): void
    {
        $this->resize(false);
    }

    /** Preserve stored UTC instants and the caller's session timezone during MySQL conversion. */
    private function resize(bool $expand): void
    {
        $connection = DB::connection($this->getConnection());
        if (! $connection instanceof MySqlConnection) {
            return;
        }
        $schema = $connection->getSchemaBuilder();
        $name = TasksConfiguration::table(TasksTables::get(TasksTables::Tasks));
        $changes = [];
        foreach (['target_at', 'due_at'] as $column) {
            $type = $schema->getColumnType($name, $column);
            if (! in_array($type, ['datetime', 'timestamp'], true)) {
                throw new LogicException('Task calendar storage must use native temporal columns.');
            }
            if ($type !== ($expand ? 'datetime' : 'timestamp')) {
                $changes[] = $column;
            }
        }
        if ($changes === []) {
            return;
        }
        $timezone = $connection->scalar('SELECT @@session.time_zone', [], false);
        if (! is_string($timezone) || $timezone === '') {
            throw new LogicException('Task calendar conversion requires a known source timezone.');
        }

        try {
            $connection->statement("SET SESSION time_zone = '+00:00'");
            if (! $expand) {
                $maximum = $connection->isMaria() && version_compare($connection->getServerVersion(), '11.5.1', '>=')
                    ? '2106-02-07 06:28:15'
                    : '2038-01-19 03:14:07';
                foreach ($changes as $column) {
                    if ($connection->table($name)->useWritePdo()->whereNotNull($column)->where(static function (Builder $query) use ($column, $maximum): void {
                        $query->where($column, '<', '1970-01-01 00:00:01')->orWhere($column, '>', $maximum);
                    })->exists()) {
                        throw new LogicException('Cannot narrow task calendar storage while dates exceed the source TIMESTAMP range.');
                    }
                }
            }
            foreach ($changes as $column) {
                $schema->table($name, static function (Blueprint $table) use ($column, $expand): void {
                    $definition = $expand ? $table->dateTime($column) : $table->timestamp($column);
                    $definition->nullable()->change();
                });
            }
        } finally {
            $connection->statement('SET SESSION time_zone = ?', [$timezone]);
        }
    }
};
