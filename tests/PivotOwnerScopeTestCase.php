<?php

declare(strict_types=1);

namespace Nvl\Tasks\Tests;

use Illuminate\Database\Schema\Blueprint;
use Nvl\Tasks\Tests\Fixtures\PivotTask;

/** Supplies the host's concrete unique native pivot before adopting the task graph. */
abstract class PivotOwnerScopeTestCase extends OwnerScopeTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('nvl-tasks.models.task', PivotTask::class);
    }

    protected function createIntegrationSchema(): void
    {
        $model = new PivotTask;
        $model->getConnection()->getSchemaBuilder()->create('consumer_task_owners', static function (Blueprint $table) use ($model): void {
            $table->uuid('task_id')->unique();
            $table->unsignedBigInteger('company_id');
            $table->foreign('task_id')->references('id')->on($model->getTable())->cascadeOnDelete();
            $table->foreign('company_id')->references('id')->on('ownership_test_companies')->restrictOnDelete();
        });
    }
}
