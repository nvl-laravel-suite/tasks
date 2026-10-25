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
    public function getConnection(): ?string
    {
        return TasksConfiguration::connection();
    }

    /** One connection-wide mutex protects cycle checks across disjoint endpoints. */
    public function up(): void
    {
        $table = TasksTables::get(TasksTables::GraphLock);
        Schema::connection($this->getConnection())->create($table, function (Blueprint $table): void {
            $table->uuid('id')->primary();
        });
        DB::connection($this->getConnection())->table($table)->insert(['id' => '00000000-0000-4000-8000-000000000001']);
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists(TasksTables::get(TasksTables::GraphLock));
    }
};
