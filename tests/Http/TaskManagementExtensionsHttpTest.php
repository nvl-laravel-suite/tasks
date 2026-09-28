<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Nvl\Tasks\Actions\CreateTaskAction;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Data\Mutations\CreateTaskData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Models\Task;

function taskExtensionsUser(string $name): User
{
    $user = new User;
    $user->setTable('users');
    $user->forceFill([
        'name' => $name,
        'email' => strtolower($name).'@example.test',
        'password' => 'unused',
    ])->save();

    return $user;
}

function allowTaskExtensions(): void
{
    app()->bind(TaskAuthorization::class, static fn () => new class implements TaskAuthorization
    {
        public function authorize(TaskAbility $ability, TaskActorData $actor, ?Task $task = null, ?Model $subject = null): void {}
    });
}

it('authorizes dashboard and detail and forwards search filters', function (): void {
    $task = app(CreateTaskAction::class)->execute(new CreateTaskData('Private'), TaskActorData::system());
    $this->actingAs(taskExtensionsUser('Visitor'));
    $this->getJson('/api/v1/tasks/dashboard')->assertForbidden();
    $this->getJson("/api/v1/tasks/{$task->id}/detail")->assertForbidden();
    $this->getJson("/api/v1/tasks/{$task->id}/checklist-items")->assertForbidden();
    $this->getJson("/api/v1/tasks/{$task->id}/time-entries")->assertForbidden();

    allowTaskExtensions();
    $review = $this->postJson('/api/v1/tasks', [
        'title' => 'Review draft',
        'type' => 'review',
        'category' => 'project',
        'importance' => 'high',
        'dueAt' => now()->subDay()->toISOString(),
        'estimatedSeconds' => 3600,
    ])->assertCreated()->json('data.id');

    $this->getJson('/api/v1/tasks/dashboard?dueSoonDays=3')->assertOk()
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.overdue', 1)
        ->assertJsonPath('data.estimatedSeconds', 3600);
    $this->getJson('/api/v1/tasks/dashboard?dueSoonDays=91')->assertUnprocessable();
    $this->getJson("/api/v1/tasks/{$review}/detail")->assertOk()
        ->assertJsonPath('data.task.id', $review)
        ->assertJsonPath('data.checklist', [])
        ->assertJsonPath('data.loggedSeconds', 0);
    $this->getJson('/api/v1/tasks?type=review&category=project&importance=high&overdue=1')
        ->assertOk()->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $review);
});

it('manages checklist, tags, manual time and timers through revision-checked routes', function (): void {
    allowTaskExtensions();
    $this->actingAs(taskExtensionsUser('Owner'));
    $task = $this->postJson('/api/v1/tasks', ['title' => 'Prepare release'])
        ->assertCreated()->json('data.id');

    $item = $this->postJson("/api/v1/tasks/{$task}/checklist-items", [
        'title' => '  Check links  ', 'expectedRevision' => 1,
    ])->assertCreated()->assertJsonPath('data.title', 'Check links')->json('data.id');
    $this->putJson("/api/v1/tasks/{$task}/checklist-items/{$item}", [
        'title' => 'Check all links', 'expectedRevision' => 2,
    ])->assertOk()->assertJsonPath('data.title', 'Check all links');
    $this->postJson("/api/v1/tasks/{$task}/checklist-items/{$item}/completion", [
        'completed' => true, 'expectedRevision' => 3,
    ])->assertOk()->assertJsonPath('data.completedById', '1');
    $this->putJson("/api/v1/tasks/{$task}/checklist-items/order", [
        'itemIds' => [$item], 'expectedRevision' => 4,
    ])->assertOk()->assertJsonPath('data.revision', 5);
    $this->getJson("/api/v1/tasks/{$task}/detail")->assertOk()
        ->assertJsonPath('data.checklist.0.title', 'Check all links');
    $this->deleteJson("/api/v1/tasks/{$task}/checklist-items/{$item}", [
        'expectedRevision' => 5,
    ])->assertNoContent();

    $this->postJson("/api/v1/tasks/{$task}/tags", [
        'tag' => ' Release ', 'expectedRevision' => 6,
    ])->assertCreated()->assertJsonPath('data.tag', 'release');
    $this->getJson("/api/v1/tasks/{$task}/detail")->assertOk()
        ->assertJsonPath('data.tags.0', 'release');
    $this->deleteJson("/api/v1/tasks/{$task}/tags/release", [
        'expectedRevision' => 7,
    ])->assertNoContent();

    $entry = $this->postJson("/api/v1/tasks/{$task}/time-entries", [
        'startedAt' => '2026-09-28T10:00:00+00:00',
        'endedAt' => '2026-09-28T10:30:00+00:00',
        'expectedRevision' => 8,
    ])->assertCreated()->assertJsonPath('data.durationSeconds', 1800)->json('data.id');
    $this->putJson("/api/v1/tasks/{$task}/time-entries/{$entry}", [
        'startedAt' => '2026-09-28T10:00:00+00:00',
        'endedAt' => '2026-09-28T11:00:00+00:00',
        'expectedRevision' => 9,
    ])->assertOk()->assertJsonPath('data.durationSeconds', 3600);
    $this->deleteJson("/api/v1/tasks/{$task}/time-entries/{$entry}", [
        'expectedRevision' => 10,
    ])->assertNoContent();

    $start = CarbonImmutable::now();
    CarbonImmutable::setTestNow($start);

    try {
        $timer = $this->postJson("/api/v1/tasks/{$task}/timer", ['expectedRevision' => 11])
            ->assertCreated()->json('data.id');
        CarbonImmutable::setTestNow($start->addSeconds(2));
        $this->postJson("/api/v1/tasks/{$task}/timer/{$timer}/stop", [
            'expectedRevision' => 12,
        ])->assertOk()->assertJsonPath('data.durationSeconds', 2);
    } finally {
        CarbonImmutable::setTestNow();
    }

    $this->postJson("/api/v1/tasks/{$task}/tags", [
        'tag' => 'Stale', 'expectedRevision' => 11,
    ])->assertStatus(409);
});

it('links and unlinks parent and blocker edges using both task revisions', function (): void {
    allowTaskExtensions();
    $this->actingAs(taskExtensionsUser('Owner'));
    $child = $this->postJson('/api/v1/tasks', ['title' => 'Child'])->assertCreated()->json('data.id');
    $parent = $this->postJson('/api/v1/tasks', ['title' => 'Parent'])->assertCreated()->json('data.id');

    $this->putJson("/api/v1/tasks/{$child}/parent/{$parent}", [
        'expectedTaskRevision' => 1, 'expectedRelatedRevision' => 1,
    ])->assertOk()->assertJsonPath('data.parentTaskId', $parent);
    $this->postJson("/api/v1/tasks/{$child}/blockers/{$parent}", [
        'expectedTaskRevision' => 2, 'expectedRelatedRevision' => 2,
    ])->assertCreated()->assertJsonPath('data.blockerTaskId', $parent);
    $this->getJson("/api/v1/tasks/{$child}/detail")->assertOk()
        ->assertJsonPath('data.parentId', $parent)
        ->assertJsonPath('data.blockerIds.0', $parent);
    $this->deleteJson("/api/v1/tasks/{$child}/blockers/{$parent}", [
        'expectedTaskRevision' => 3, 'expectedRelatedRevision' => 3,
    ])->assertNoContent();
    $this->deleteJson("/api/v1/tasks/{$child}/parent/{$parent}", [
        'expectedTaskRevision' => 4, 'expectedRelatedRevision' => 4,
    ])->assertNoContent();
    $this->getJson("/api/v1/tasks/{$child}/detail")->assertOk()
        ->assertJsonPath('data.parentId', null)
        ->assertJsonPath('data.blockerIds', []);
});

it('pages checklist and time history beyond bounded task detail', function (): void {
    allowTaskExtensions();
    $this->actingAs(taskExtensionsUser('Owner'));
    config()->set('tasks.detail.maximum_checklist_items', 1);
    config()->set('tasks.detail.maximum_time_entries', 1);
    $task = $this->postJson('/api/v1/tasks', ['title' => 'History'])->assertCreated()->json('data.id');

    foreach (range(1, 3) as $number) {
        $this->postJson("/api/v1/tasks/{$task}/checklist-items", [
            'title' => "Item {$number}",
            'expectedRevision' => $number,
        ])->assertCreated();
    }

    foreach (range(1, 3) as $number) {
        $this->postJson("/api/v1/tasks/{$task}/time-entries", [
            'startedAt' => "2026-09-2{$number}T10:00:00+00:00",
            'endedAt' => "2026-09-2{$number}T10:30:00+00:00",
            'expectedRevision' => $number + 3,
        ])->assertCreated();
    }

    $this->getJson("/api/v1/tasks/{$task}/detail")->assertOk()
        ->assertJsonCount(1, 'data.checklist')
        ->assertJsonCount(1, 'data.timeEntries');
    $this->getJson("/api/v1/tasks/{$task}/checklist-items?perPage=2&page=2")->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('data.0.title', 'Item 3');
    $this->getJson("/api/v1/tasks/{$task}/time-entries?perPage=2&page=2")->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('data.0.durationSeconds', 1800);
});
