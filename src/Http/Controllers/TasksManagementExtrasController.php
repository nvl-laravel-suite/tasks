<?php

declare(strict_types=1);

namespace Nvl\Tasks\Http\Controllers;

use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Nvl\Support\Http\PackageExceptionPayload;
use Nvl\Tasks\Actions\AddTaskChecklistItemAction;
use Nvl\Tasks\Actions\AddTaskDependencyAction;
use Nvl\Tasks\Actions\AddTaskTagAction;
use Nvl\Tasks\Actions\AddTaskTimeEntryAction;
use Nvl\Tasks\Actions\DeleteTaskTimeEntryAction;
use Nvl\Tasks\Actions\GetTaskDashboardAction;
use Nvl\Tasks\Actions\GetTaskDetailAction;
use Nvl\Tasks\Actions\LinkTaskParentAction;
use Nvl\Tasks\Actions\ListTaskChecklistItemsAction;
use Nvl\Tasks\Actions\ListTaskTimeEntriesAction;
use Nvl\Tasks\Actions\RemoveTaskChecklistItemAction;
use Nvl\Tasks\Actions\RemoveTaskDependencyAction;
use Nvl\Tasks\Actions\RemoveTaskTagAction;
use Nvl\Tasks\Actions\ReorderTaskChecklistItemsAction;
use Nvl\Tasks\Actions\StartTaskTimerAction;
use Nvl\Tasks\Actions\StopTaskTimerAction;
use Nvl\Tasks\Actions\ToggleTaskChecklistItemAction;
use Nvl\Tasks\Actions\UnlinkTaskParentAction;
use Nvl\Tasks\Actions\UpdateTaskChecklistItemAction;
use Nvl\Tasks\Actions\UpdateTaskTimeEntryAction;
use Nvl\Tasks\Data\Mutations\AddTaskChecklistItemData;
use Nvl\Tasks\Data\Mutations\RemoveTaskChecklistItemData;
use Nvl\Tasks\Data\Mutations\ReorderTaskChecklistItemsData;
use Nvl\Tasks\Data\Mutations\TaskTagMutationData;
use Nvl\Tasks\Data\Mutations\TimeEntryData;
use Nvl\Tasks\Data\Mutations\ToggleTaskChecklistItemData;
use Nvl\Tasks\Data\Mutations\UpdateTaskChecklistItemData;
use Nvl\Tasks\Data\TaskChecklistItemData;
use Nvl\Tasks\Data\TaskData;
use Nvl\Tasks\Exceptions\TaskRevisionConflict;
use Nvl\Tasks\Models\TaskChecklistItem;
use Nvl\Tasks\Models\TaskTimeEntry;
use Nvl\Tasks\Support\TaskActorFactory;
use Nvl\Tasks\Support\TasksConfiguration;

/** Opt-in JSON transport for task dashboard and package-owned child workflows. */
final class TasksManagementExtrasController extends Controller
{
    public function __construct(private readonly PackageExceptionPayload $payload) {}

    /** Return a tenant-scoped dashboard summary after list authorization. */
    public function dashboard(Request $request, TaskActorFactory $actors, GetTaskDashboardAction $action): JsonResponse
    {
        $actor = $actors->fromRequest($request);
        $values = Validator::make($request->query(), [
            'dueSoonDays' => ['nullable', 'integer', 'min:1', 'max:90'],
        ])->validate();

        return response()->json([
            'data' => $action->execute($actor, isset($values['dueSoonDays'])
                ? $this->validatedInteger($values['dueSoonDays']) : null)->toArray(),
        ]);
    }

    /** Return one authorized task with bounded package-owned detail. */
    public function detail(string $task, Request $request, TaskActorFactory $actors, GetTaskDetailAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute($task, $actors->fromRequest($request))->toArray()]);
    }

    /** Page the full checklist beyond the bounded detail projection. */
    public function checklistItems(
        string $task,
        Request $request,
        TaskActorFactory $actors,
        ListTaskChecklistItemsAction $action,
    ): JsonResponse {
        [$perPage, $page] = $this->pageQuery($request);
        $items = $action->execute($task, $actors->fromRequest($request), $perPage, $page);

        return response()->json([
            'data' => array_map(
                static fn (TaskChecklistItem $item): array => TaskChecklistItemData::fromModel($item)->toArray(),
                $items->items(),
            ),
            'meta' => $this->pageMeta($items),
        ]);
    }

    /** Page the full time history beyond the bounded detail projection. */
    public function timeEntries(
        string $task,
        Request $request,
        TaskActorFactory $actors,
        ListTaskTimeEntriesAction $action,
    ): JsonResponse {
        [$perPage, $page] = $this->pageQuery($request);
        $items = $action->execute($task, $actors->fromRequest($request), $perPage, $page);

        return response()->json([
            'data' => array_map(fn (TaskTimeEntry $entry): array => $this->timeEntry($entry), $items->items()),
            'meta' => $this->pageMeta($items),
        ]);
    }

    /** Append one checklist item. */
    public function addChecklistItem(
        string $task,
        Request $request,
        TaskActorFactory $actors,
        AddTaskChecklistItemAction $action,
    ): JsonResponse {
        $actor = $actors->fromRequest($request);
        $data = AddTaskChecklistItemData::validateAndCreate($request->all());

        return $this->mutate(
            static fn (): array => TaskChecklistItemData::fromModel($action->execute($task, $data, $actor))->toArray(),
            201,
        );
    }

    /** Replace a checklist item title. */
    public function updateChecklistItem(
        string $task,
        string $item,
        Request $request,
        TaskActorFactory $actors,
        UpdateTaskChecklistItemAction $action,
    ): JsonResponse {
        $actor = $actors->fromRequest($request);
        $data = UpdateTaskChecklistItemData::validateAndCreate($request->all());

        return $this->mutate(
            static fn (): array => TaskChecklistItemData::fromModel($action->execute($task, $item, $data, $actor))->toArray(),
        );
    }

    /** Set checklist item completion and its actor. */
    public function toggleChecklistItem(
        string $task,
        string $item,
        Request $request,
        TaskActorFactory $actors,
        ToggleTaskChecklistItemAction $action,
    ): JsonResponse {
        $actor = $actors->fromRequest($request);
        $data = ToggleTaskChecklistItemData::validateAndCreate($request->all());

        return $this->mutate(
            static fn (): array => TaskChecklistItemData::fromModel($action->execute($task, $item, $data, $actor))->toArray(),
        );
    }

    /** Apply a complete checklist order. */
    public function reorderChecklistItems(
        string $task,
        Request $request,
        TaskActorFactory $actors,
        ReorderTaskChecklistItemsAction $action,
    ): JsonResponse {
        $actor = $actors->fromRequest($request);
        $data = ReorderTaskChecklistItemsData::validateAndCreate($request->all());

        return $this->mutate(static fn (): array => TaskData::fromModel($action->execute($task, $data, $actor))->toArray());
    }

    /** Remove a checklist item. */
    public function removeChecklistItem(
        string $task,
        string $item,
        Request $request,
        TaskActorFactory $actors,
        RemoveTaskChecklistItemAction $action,
    ): JsonResponse {
        $actor = $actors->fromRequest($request);
        $data = RemoveTaskChecklistItemData::validateAndCreate($request->all());

        return $this->mutate(static function () use ($action, $actor, $data, $item, $task): null {
            $action->execute($task, $item, $data, $actor);

            return null;
        }, 204);
    }

    /** Add one normalized task tag. */
    public function addTag(string $task, Request $request, TaskActorFactory $actors, AddTaskTagAction $action): JsonResponse
    {
        $actor = $actors->fromRequest($request);
        $data = TaskTagMutationData::validateAndCreate($request->all());

        return $this->mutate(static function () use ($action, $actor, $data, $task): array {
            $tag = $action->execute($task, $data, $actor);

            return ['id' => $tag->id, 'tag' => $tag->tag];
        }, 201);
    }

    /** Remove one normalized task tag. */
    public function removeTag(
        string $task,
        string $tag,
        Request $request,
        TaskActorFactory $actors,
        RemoveTaskTagAction $action,
    ): JsonResponse {
        $actor = $actors->fromRequest($request);
        $data = TaskTagMutationData::validateAndCreate([
            ...$request->all(),
            'tag' => $tag,
        ]);

        return $this->mutate(static function () use ($action, $actor, $data, $task): null {
            $action->execute($task, $data, $actor);

            return null;
        }, 204);
    }

    /** Store a manual time interval. */
    public function addTimeEntry(
        string $task,
        Request $request,
        TaskActorFactory $actors,
        AddTaskTimeEntryAction $action,
    ): JsonResponse {
        $actor = $actors->fromRequest($request);
        $data = TimeEntryData::validateAndCreate($request->all());

        return $this->mutate(fn (): array => $this->timeEntry($action->execute($task, $data, $actor)), 201);
    }

    /** Replace a performer's manual time interval. */
    public function updateTimeEntry(
        string $task,
        string $entry,
        Request $request,
        TaskActorFactory $actors,
        UpdateTaskTimeEntryAction $action,
    ): JsonResponse {
        $actor = $actors->fromRequest($request);
        $data = TimeEntryData::validateAndCreate($request->all());

        return $this->mutate(fn (): array => $this->timeEntry($action->execute($task, $entry, $data, $actor)));
    }

    /** Remove a performer's time interval. */
    public function deleteTimeEntry(
        string $task,
        string $entry,
        Request $request,
        TaskActorFactory $actors,
        DeleteTaskTimeEntryAction $action,
    ): JsonResponse {
        $actor = $actors->fromRequest($request);
        $revision = $this->expectedRevision($request);

        return $this->mutate(static function () use ($action, $actor, $entry, $revision, $task): null {
            $action->execute($task, $entry, $revision, $actor);

            return null;
        }, 204);
    }

    /** Start one performer timer. */
    public function startTimer(
        string $task,
        Request $request,
        TaskActorFactory $actors,
        StartTaskTimerAction $action,
    ): JsonResponse {
        $actor = $actors->fromRequest($request);
        $revision = $this->expectedRevision($request);

        return $this->mutate(fn (): array => $this->timeEntry($action->execute($task, $revision, $actor)), 201);
    }

    /** Stop one performer timer. */
    public function stopTimer(
        string $task,
        string $entry,
        Request $request,
        TaskActorFactory $actors,
        StopTaskTimerAction $action,
    ): JsonResponse {
        $actor = $actors->fromRequest($request);
        $revision = $this->expectedRevision($request);

        return $this->mutate(fn (): array => $this->timeEntry($action->execute($task, $entry, $revision, $actor)));
    }

    /** Attach a parent task after validating both revisions. */
    public function linkParent(
        string $task,
        string $related,
        Request $request,
        TaskActorFactory $actors,
        LinkTaskParentAction $action,
    ): JsonResponse {
        $actor = $actors->fromRequest($request);
        [$taskRevision, $relatedRevision] = $this->graphRevisions($request);

        return $this->mutate(static function () use ($action, $actor, $related, $relatedRevision, $task, $taskRevision): array {
            $link = $action->execute($task, $related, $taskRevision, $relatedRevision, $actor);

            return ['id' => $link->id, 'childTaskId' => $link->child_task_id, 'parentTaskId' => $link->parent_task_id];
        });
    }

    /** Detach a specified parent task. */
    public function unlinkParent(
        string $task,
        string $related,
        Request $request,
        TaskActorFactory $actors,
        UnlinkTaskParentAction $action,
    ): JsonResponse {
        $actor = $actors->fromRequest($request);
        [$taskRevision, $relatedRevision] = $this->graphRevisions($request);

        return $this->mutate(static function () use ($action, $actor, $related, $relatedRevision, $task, $taskRevision): null {
            $action->execute($task, $related, $taskRevision, $relatedRevision, $actor);

            return null;
        }, 204);
    }

    /** Add one blocker task. */
    public function addBlocker(
        string $task,
        string $related,
        Request $request,
        TaskActorFactory $actors,
        AddTaskDependencyAction $action,
    ): JsonResponse {
        $actor = $actors->fromRequest($request);
        [$taskRevision, $relatedRevision] = $this->graphRevisions($request);

        return $this->mutate(static function () use ($action, $actor, $related, $relatedRevision, $task, $taskRevision): array {
            $link = $action->execute($task, $related, $taskRevision, $relatedRevision, $actor);

            return ['id' => $link->id, 'taskId' => $link->task_id, 'blockerTaskId' => $link->blocker_task_id];
        }, 201);
    }

    /** Remove one blocker task. */
    public function removeBlocker(
        string $task,
        string $related,
        Request $request,
        TaskActorFactory $actors,
        RemoveTaskDependencyAction $action,
    ): JsonResponse {
        $actor = $actors->fromRequest($request);
        [$taskRevision, $relatedRevision] = $this->graphRevisions($request);

        return $this->mutate(static function () use ($action, $actor, $related, $relatedRevision, $task, $taskRevision): null {
            $action->execute($task, $related, $taskRevision, $relatedRevision, $actor);

            return null;
        }, 204);
    }

    /** Return one validated task revision. */
    private function expectedRevision(Request $request): int
    {
        $values = Validator::make($request->all(), [
            'expectedRevision' => ['required', 'integer', 'min:1'],
        ])->validate();

        return $this->validatedInteger($values['expectedRevision']);
    }

    /** Validate a bounded page request shared by child collection readers.
     *
     * @return array{?int, int}
     */
    private function pageQuery(Request $request): array
    {
        $values = Validator::make($request->query(), [
            'perPage' => ['nullable', 'integer', 'min:1', 'max:'.TasksConfiguration::limit('maximum_page_size', 100)],
            'page' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        return [
            isset($values['perPage']) ? $this->validatedInteger($values['perPage']) : null,
            isset($values['page']) ? $this->validatedInteger($values['page']) : 1,
        ];
    }

    /** Project Laravel pagination metadata used by task collection routes.
     *
     * @template TValue
     *
     * @param  LengthAwarePaginator<int, TValue>  $items
     * @return array{current_page: int, last_page: int, per_page: int, total: int}
     */
    private function pageMeta(LengthAwarePaginator $items): array
    {
        return [
            'current_page' => $items->currentPage(),
            'last_page' => $items->lastPage(),
            'per_page' => $items->perPage(),
            'total' => $items->total(),
        ];
    }

    /** Return validated revisions for both graph endpoints.
     *
     * @return array{int, int}
     */
    private function graphRevisions(Request $request): array
    {
        $values = Validator::make($request->all(), [
            'expectedTaskRevision' => ['required', 'integer', 'min:1'],
            'expectedRelatedRevision' => ['required', 'integer', 'min:1'],
        ])->validate();

        return [
            $this->validatedInteger($values['expectedTaskRevision']),
            $this->validatedInteger($values['expectedRelatedRevision']),
        ];
    }

    /** Convert a Laravel-validated integer while rejecting unexpected input shapes. */
    private function validatedInteger(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '' && strspn($value, '0123456789') === strlen($value)) {
            return (int) $value;
        }

        throw new InvalidArgumentException('A task revision or dashboard window must be an integer.');
    }

    /** Project one time entry without exposing Eloquent internals.
     *
     * @return array<string, int|string|null>
     */
    private function timeEntry(TaskTimeEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'taskId' => $entry->task_id,
            'performerType' => $entry->performer_type,
            'performerId' => $entry->performer_id,
            'startedAt' => $entry->started_at->toISOString(),
            'stoppedAt' => $entry->stopped_at?->toISOString(),
            'durationSeconds' => $entry->duration_seconds,
            'description' => $entry->description,
        ];
    }

    /** Map task revision conflicts and graph validation to HTTP responses.
     *
     * @param  Closure(): (array<string, mixed>|null)  $callback
     */
    private function mutate(Closure $callback, int $status = 200): JsonResponse
    {
        try {
            $data = $callback();

            return $status === 204
                ? response()->json(status: 204)
                : response()->json(['data' => $data], $status);
        } catch (TaskRevisionConflict $exception) {
            return response()->json(['message' => $this->payload->for($exception)['message']], 409);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
