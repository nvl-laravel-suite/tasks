<?php

declare(strict_types=1);

namespace Nvl\Tasks\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Nvl\Tasks\Actions\AssignTaskAction;
use Nvl\Tasks\Actions\CreateTaskAction;
use Nvl\Tasks\Actions\DeleteTaskAction;
use Nvl\Tasks\Actions\GetTaskAction;
use Nvl\Tasks\Actions\ListTasksAction;
use Nvl\Tasks\Actions\RestoreTaskAction;
use Nvl\Tasks\Actions\UnassignTaskAction;
use Nvl\Tasks\Actions\UpdateTaskAction;
use Nvl\Tasks\Contracts\TaskPrincipalResolver;
use Nvl\Tasks\Data\Mutations\AssignTaskData;
use Nvl\Tasks\Data\Mutations\CreateTaskData;
use Nvl\Tasks\Data\Mutations\RestoreTaskData;
use Nvl\Tasks\Data\Mutations\UpdateTaskData;
use Nvl\Tasks\Data\Queries\TaskIndexQueryData;
use Nvl\Tasks\Data\TaskActorData;
use Nvl\Tasks\Data\TaskAssignmentData;
use Nvl\Tasks\Data\TaskData;
use Nvl\Tasks\Exceptions\TaskRevisionConflict;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Support\TaskActorFactory;
use Spatie\LaravelData\Optional;

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
        $query = TaskIndexQueryData::validateAndCreate($request->query());
        $actor = $actors->fromRequest($request);

        $tasks = $action->execute(
            actor: $actor,
            status: $query->status,
            priority: $query->priority,
            assignee: $query->assigneeId !== null
                ? TaskActorData::fromAuthenticatable($principals->resolve($query->assigneeId))
                : null,
            perPage: $query->perPage,
            type: $query->type,
            category: $query->category,
            importance: $query->importance,
            tag: $query->tag,
            targetFrom: $query->targetFrom,
            targetTo: $query->targetTo,
            dueFrom: $query->dueFrom,
            dueTo: $query->dueTo,
            overdue: $query->overdue,
        );

        return response()->json([
            'data' => array_map(
                static fn (Task $task): array => TaskData::fromModel($task)->toArray(),
                $tasks->items(),
            ),
            'meta' => [
                'current_page' => $tasks->currentPage(),
                'last_page' => $tasks->lastPage(),
                'per_page' => $tasks->perPage(),
                'total' => $tasks->total(),
            ],
        ]);
    }

    /** Create a task from validated app input. */
    public function store(Request $request, TaskActorFactory $actors, CreateTaskAction $action): JsonResponse
    {
        $task = $action->execute(
            $this->createData($request),
            $actors->fromRequest($request),
        );

        return response()->json(['data' => TaskData::fromModel($task)->toArray()], 201);
    }

    /** Read a task through the tenant and consumer-policy boundaries. */
    public function show(string $task, Request $request, TaskActorFactory $actors, GetTaskAction $action): JsonResponse
    {
        return response()->json([
            'data' => TaskData::fromModel($action->execute($task, $actors->fromRequest($request)))->toArray(),
        ]);
    }

    /** Replace a task using an exact revision. */
    public function update(string $task, Request $request, TaskActorFactory $actors, UpdateTaskAction $action): JsonResponse
    {
        try {
            $updated = $action->execute(
                $task,
                $this->updateData($request),
                $actors->fromRequest($request),
            );
        } catch (TaskRevisionConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => TaskData::fromModel($updated)->toArray()]);
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
        $data = RestoreTaskData::validateAndCreate($request->all());

        try {
            $restored = $action->execute(
                $task,
                $data->expectedRevision,
                $actors->fromRequest($request),
            );
        } catch (TaskRevisionConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => TaskData::fromModel($restored)->toArray()]);
    }

    /** Assign one host-resolved principal without changing task ownership. */
    public function assign(
        string $task,
        Request $request,
        TaskActorFactory $actors,
        TaskPrincipalResolver $principals,
        AssignTaskAction $action,
    ): JsonResponse {
        $data = AssignTaskData::validateAndCreate($request->all());
        $actor = $actors->fromRequest($request);
        $assignment = $action->execute(
            $task,
            $principals->resolve($data->assigneeId),
            $actor,
        );

        return response()->json(['data' => TaskAssignmentData::fromModel($assignment)->toArray()], 201);
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

    /** Validate JSON values before constructing a transport-safe task creation DTO. */
    private function createData(Request $request): CreateTaskData
    {
        $values = Validator::make($request->all(), CreateTaskData::rules())->validate();

        return new CreateTaskData(
            title: $this->requiredString($values, 'title'),
            description: $this->nullableString($values, 'description'),
            priority: $this->nullableString($values, 'priority'),
            status: $this->nullableString($values, 'status'),
            dueAt: $this->nullableString($values, 'dueAt'),
            metadata: $this->metadata($values),
            type: $this->nullableString($values, 'type'),
            category: $this->nullableString($values, 'category'),
            importance: $this->nullableString($values, 'importance'),
            targetAt: $this->nullableString($values, 'targetAt'),
            estimatedSeconds: $this->nullableInteger($values, 'estimatedSeconds'),
        );
    }

    /** Validate JSON values before constructing a transport-safe task replacement DTO. */
    private function updateData(Request $request): UpdateTaskData
    {
        $values = Validator::make($request->all(), UpdateTaskData::rules())->validate();

        return new UpdateTaskData(
            title: $this->requiredString($values, 'title'),
            priority: $this->requiredString($values, 'priority'),
            status: $this->requiredString($values, 'status'),
            expectedRevision: $this->requiredInteger($values, 'expectedRevision'),
            description: $this->optionalString($values, 'description'),
            dueAt: $this->optionalString($values, 'dueAt'),
            metadata: array_key_exists('metadata', $values) ? $this->metadata($values) : new Optional,
            type: $this->optionalString($values, 'type'),
            category: $this->optionalString($values, 'category'),
            importance: $this->optionalString($values, 'importance'),
            targetAt: $this->optionalString($values, 'targetAt'),
            estimatedSeconds: array_key_exists('estimatedSeconds', $values)
                ? $this->nullableInteger($values, 'estimatedSeconds')
                : new Optional,
        );
    }

    /** @param  array<mixed>  $values */
    private function requiredString(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        if (! is_string($value)) {
            throw new InvalidArgumentException("The validated task field [{$key}] must be a string.");
        }

        return $value;
    }

    /** @param  array<mixed>  $values */
    private function nullableString(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException("The validated task field [{$key}] must be a string or null.");
        }

        return $value;
    }

    /** @param  array<mixed>  $values */
    private function optionalString(array $values, string $key): string|Optional|null
    {
        return array_key_exists($key, $values)
            ? $this->nullableString($values, $key)
            : new Optional;
    }

    /** @param  array<mixed>  $values */
    private function requiredInteger(array $values, string $key): int
    {
        $value = $values[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '' && strspn($value, '0123456789') === strlen($value)) {
            return (int) $value;
        }

        throw new InvalidArgumentException("The validated task field [{$key}] must be an integer.");
    }

    /** @param  array<mixed>  $values */
    private function nullableInteger(array $values, string $key): ?int
    {
        return ($values[$key] ?? null) === null ? null : $this->requiredInteger($values, $key);
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, mixed>
     */
    private function metadata(array $values): array
    {
        $metadata = $values['metadata'] ?? [];

        if (! is_array($metadata)) {
            throw ValidationException::withMessages(['metadata' => 'Task metadata must be an object.']);
        }

        $normalized = [];

        foreach ($metadata as $key => $value) {
            if (! is_string($key)) {
                throw ValidationException::withMessages(['metadata' => 'Task metadata keys must be strings.']);
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
