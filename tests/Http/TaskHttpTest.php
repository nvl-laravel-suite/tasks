<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Route;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Contracts\TaskPrincipalResolver;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Http\Controllers\TasksManagementController;
use Nvl\Tasks\Models\Task;

function taskHttpUser(string $name): User
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

it('keeps management routes behind consumer authorization', function (): void {
    expect(Route::has('nvl.tasks.management.index'))->toBeTrue();

    $this->actingAs(taskHttpUser('Owner'))
        ->postJson('/api/v1/tasks', ['title' => 'Review draft'])
        ->assertForbidden();
});

it('rejects unauthenticated actor resolution before looking up host principals', function (): void {
    Route::get('/test/unprotected-tasks', [TasksManagementController::class, 'index']);
    Route::post('/test/unprotected-tasks/{task}/assignees', [TasksManagementController::class, 'assign']);

    $this->getJson('/test/unprotected-tasks?assigneeId=123')->assertUnauthorized();
    $this->postJson('/test/unprotected-tasks/123/assignees', ['assigneeId' => '123'])
        ->assertUnauthorized();
});

it('exposes bounded task rows, exact-revision writes, and host-resolved assignment', function (): void {
    app()->bind(TaskAuthorization::class, static fn () => new class implements TaskAuthorization
    {
        public function authorize(TaskAbility $ability, TaskActorData $actor, ?Task $task = null, ?Model $subject = null): void
        {
            if ($actor->system) {
                throw new AuthorizationException;
            }
        }
    });
    app()->bind(TaskPrincipalResolver::class, static fn () => new class implements TaskPrincipalResolver
    {
        public function resolve(string $identifier): Model&Authenticatable
        {
            return User::query()->findOrFail($identifier);
        }
    });

    $owner = taskHttpUser('Owner');
    $assignee = taskHttpUser('Assignee');
    $this->actingAs($owner);

    $taskId = $this->postJson('/api/v1/tasks', [
        'title' => 'Review draft',
        'description' => 'Check the copy.',
        'metadata' => ['source' => 'editorial'],
    ])->assertCreated()->json('data.id');

    $this->getJson('/api/v1/tasks?status=open&perPage=10')
        ->assertOk()->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $taskId)
        ->assertJsonPath('data.0.assigneesCount', 0);

    $this->postJson("/api/v1/tasks/{$taskId}/assignees", [
        'assigneeId' => (string) $assignee->getKey(),
    ])->assertCreated()->assertJsonPath('data.assigneeId', (string) $assignee->getKey());

    $this->getJson('/api/v1/tasks?assigneeId='.$assignee->getKey())
        ->assertOk()->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.assigneesCount', 1);

    $this->putJson("/api/v1/tasks/{$taskId}", [
        'title' => 'Approved draft',
        'status' => 'completed',
        'priority' => 'high',
        'expectedRevision' => 2,
    ])->assertOk()->assertJsonPath('data.status', 'completed');

    $this->putJson("/api/v1/tasks/{$taskId}", [
        'title' => 'Stale draft',
        'status' => 'open',
        'priority' => 'low',
        'expectedRevision' => 2,
    ])->assertStatus(409);

    $this->deleteJson("/api/v1/tasks/{$taskId}/assignees/{$assignee->getKey()}")->assertNoContent();
    $this->deleteJson("/api/v1/tasks/{$taskId}")->assertNoContent();
    $this->getJson("/api/v1/tasks/{$taskId}")->assertNotFound();
    $this->postJson("/api/v1/tasks/{$taskId}/restore", ['expectedRevision' => 3])->assertStatus(409);
    $this->postJson("/api/v1/tasks/{$taskId}/restore", ['expectedRevision' => 4])
        ->assertOk()->assertJsonPath('data.revision', 5);
    $this->getJson("/api/v1/tasks/{$taskId}")->assertOk();
});
