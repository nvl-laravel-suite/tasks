<?php

declare(strict_types=1);

namespace Nvl\Tasks\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Nvl\Tasks\Actions\AssignTaskAction;
use Nvl\Tasks\Actions\CreateTaskAction;
use Nvl\Tasks\Actions\DeleteTaskAction;
use Nvl\Tasks\Actions\GetTaskAction;
use Nvl\Tasks\Actions\ListTasksAction;
use Nvl\Tasks\Actions\RestoreTaskAction;
use Nvl\Tasks\Actions\UnassignTaskAction;
use Nvl\Tasks\Actions\UpdateTaskAction;
use Nvl\Tasks\Contracts\TaskPrincipalResolver;
use Nvl\Tasks\Data\Mutations\CreateTaskData;
use Nvl\Tasks\Data\Mutations\UpdateTaskData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Enums\TaskPriority;
use Nvl\Tasks\Enums\TaskStatus;
use Nvl\Tasks\Exceptions\TaskRevisionConflict;
use Nvl\Tasks\Http\Resources\TaskAssignmentResource;
use Nvl\Tasks\Http\Resources\TaskResource;
use Nvl\Tasks\Support\TaskActorFactory;
use Nvl\Tasks\Support\TasksConfiguration;

/** Thin opt-in task management transport over the package's public actions. */
final class TasksManagementController extends Controller
{
    /** Return one bounded, authorized task table page. */
    public function index(
        Request $request,
        TaskActorFactory $actors,
        TaskPrincipalResolver $principals,
        ListTasksAction $action,
    ): JsonResponse {
        $query = Validator::make($request->query(), [
            'status' => ['sometimes', Rule::enum(TaskStatus::class)],
            'priority' => ['sometimes', Rule::enum(TaskPriority::class)],
            'assigneeId' => ['sometimes', 'string', 'max:191'],
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:'.TasksConfiguration::limit('maximum_page_size', 100)],
        ])->validate();
        $status = $query['status'] ?? null;
        $priority = $query['priority'] ?? null;
        $assignee = $query['assigneeId'] ?? null;
        $perPage = $query['perPage'] ?? null;
        $actor = $actors->fromRequest($request);

        $tasks = $action->execute(
            actor: $actor,
            status: is_string($status) ? TaskStatus::from($status) : null,
            priority: is_string($priority) ? TaskPriority::from($priority) : null,
            assignee: is_string($assignee)
                ? TaskActorData::fromAuthenticatable($principals->resolve($assignee))
                : null,
            perPage: is_string($perPage) || is_int($perPage) ? (int) $perPage : null,
        );

        return TaskResource::collection($tasks)->response();
    }

    /** Create a task from validated app input. */
    public function store(Request $request, TaskActorFactory $actors, CreateTaskAction $action): JsonResponse
    {
        $task = $action->execute(
            CreateTaskData::validateAndCreate($request->all()),
            $actors->fromRequest($request),
        );

        return TaskResource::make($task)->response()->setStatusCode(201);
    }

    /** Read a task through the tenant and consumer-policy boundaries. */
    public function show(string $task, Request $request, TaskActorFactory $actors, GetTaskAction $action): JsonResponse
    {
        return TaskResource::make($action->execute($task, $actors->fromRequest($request)))->response();
    }

    /** Replace a task using an exact revision. */
    public function update(string $task, Request $request, TaskActorFactory $actors, UpdateTaskAction $action): JsonResponse
    {
        try {
            $updated = $action->execute(
                $task,
                UpdateTaskData::validateAndCreate($request->all()),
                $actors->fromRequest($request),
            );
        } catch (TaskRevisionConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return TaskResource::make($updated)->response();
    }

    /** Soft-delete one authorized task. */
    public function destroy(string $task, Request $request, TaskActorFactory $actors, DeleteTaskAction $action): JsonResponse
    {
        $action->execute($task, $actors->fromRequest($request));

        return response()->json(status: 204);
    }

    /** Restore a deleted task only from a matching revision. */
    public function restore(
        string $task,
        Request $request,
        TaskActorFactory $actors,
        RestoreTaskAction $action,
    ): JsonResponse {
        Validator::make($request->all(), [
            'expectedRevision' => ['required', 'integer', 'min:1'],
        ])->validate();

        try {
            $restored = $action->execute(
                $task,
                $request->integer('expectedRevision'),
                $actors->fromRequest($request),
            );
        } catch (TaskRevisionConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return TaskResource::make($restored)->response();
    }

    /** Assign one host-resolved principal without changing task ownership. */
    public function assign(
        string $task,
        Request $request,
        TaskActorFactory $actors,
        TaskPrincipalResolver $principals,
        AssignTaskAction $action,
    ): JsonResponse {
        Validator::make($request->all(), ['assigneeId' => ['required', 'string', 'max:191']])->validate();
        $actor = $actors->fromRequest($request);
        $assignment = $action->execute(
            $task,
            $principals->resolve($request->string('assigneeId')->toString()),
            $actor,
        );

        return TaskAssignmentResource::make($assignment)->response()->setStatusCode(201);
    }

    /** Remove an assignment through the same host principal resolver. */
    public function unassign(
        string $task,
        string $assignee,
        Request $request,
        TaskActorFactory $actors,
        TaskPrincipalResolver $principals,
        UnassignTaskAction $action,
    ): JsonResponse {
        $actor = $actors->fromRequest($request);
        $action->execute($task, $principals->resolve($assignee), $actor);

        return response()->json(status: 204);
    }
}
