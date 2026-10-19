<?php

declare(strict_types=1);

use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskTag;
use Nvl\Tasks\Tests\Fixtures\OwnedTask;
use Nvl\Tasks\Tests\OwnerScopeTestCase;

uses(OwnerScopeTestCase::class);

it('uses configured models and canonical ownership in root and child factories', function (): void {
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), function (): void {
        $task = Task::factory()->create();
        $tag = TaskTag::factory()->create(['task_id' => $task->id]);
        expect($task)->toBeInstanceOf(OwnedTask::class)
            ->and($task->fresh()->getAttribute('company_id'))->toBe(7)
            ->and($tag->task)->toBeInstanceOf(OwnedTask::class)
            ->and($tag->task->id)->toBe($task->id);
    });
});
