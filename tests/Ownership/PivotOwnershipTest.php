<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Ownership\Exceptions\OwnershipException;
use Nvl\Tasks\Actions\CreateTaskAction;
use Nvl\Tasks\Data\Mutations\CreateTaskData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Services\TaskModelRegistry;
use Nvl\Tasks\Tests\PivotOwnerScopeTestCase;

uses(PivotOwnerScopeTestCase::class);

it('atomically writes the concrete primary pivot and forbids replacing or removing ownership', function (): void {
    $actor = Actor::query()->findOrFail(1);
    $task = app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), $actor, function () {
        $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Pivot'), TaskActorData::system());
        $connection = $task->getConnection();
        expect($connection->table('consumer_task_owners')->where('task_id', $task->id)->value('company_id'))->toBe(7);
        expect(fn () => $connection->table('consumer_task_owners')->where('task_id', $task->id)->update(['company_id' => 8]))->toThrow(QueryException::class);
        expect(fn () => $connection->table('consumer_task_owners')->where('task_id', $task->id)->delete())->toThrow(QueryException::class);
        expect(fn () => Task::query()->get())->toThrow(OwnershipException::class);

        return $task;
    });
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(8), $actor, function () use ($task): void {
        expect(TaskModelRegistry::query()->whereKey($task->id)->exists())->toBeFalse();
    });
});
