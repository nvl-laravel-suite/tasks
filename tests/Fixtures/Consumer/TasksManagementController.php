<?php

declare(strict_types=1);

namespace Nvl\Tasks\Tests\Fixtures\Consumer;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Nvl\Tasks\Services\TaskAssignments;
use Nvl\Tasks\Services\TaskChecklist;
use Nvl\Tasks\Services\TaskLifecycle;
use Nvl\Tasks\Services\TaskReader;
use Nvl\Tasks\Services\TaskRelationships;
use Nvl\Tasks\Services\TaskTags;
use Nvl\Tasks\Services\TaskTimeTracking;

/** Consumer-owned HTTP mapping to complete native capabilities. */
final readonly class TasksManagementController
{
    public function __construct(private TaskLifecycle $tasks, private TaskReader $reader, private TaskAssignments $assignments, private TaskChecklist $checklist, private TaskTags $tags, private TaskTimeTracking $time, private TaskRelationships $relationships) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $request->validate(['per_page' => 'sometimes|integer|min:1|max:100', 'page' => 'sometimes|integer|min:1', 'overdue' => 'sometimes|boolean']);
        $criteria = $this->attributes($request->except(['per_page', 'page']));
        if (array_key_exists('overdue', $criteria)) {
            $criteria['overdue'] = $request->boolean('overdue');
        }
        if (isset($criteria['assignee_id'])) {
            $criteria['assignee'] = app(TaskPrincipalResolver::class)->resolve((string) $criteria['assignee_id']);
            unset($criteria['assignee_id']);
        }

        return $this->page($this->reader->paginate($criteria, $actor, (int) $request->input('per_page', 25), (int) $request->input('page', 1)));
    }

    public function store(Request $request): JsonResponse
    {
        return $this->model($this->tasks->create($this->attributes($request->all()), $this->actor($request)), 201);
    }

    public function show(Request $request, string $task): JsonResponse
    {
        return $this->model($this->reader->get($task, $this->actor($request)));
    }

    public function update(Request $request, string $task): JsonResponse
    {
        return $this->model($this->tasks->update($task, $this->attributes($request->except('expected_revision')), $this->revision($request), $this->actor($request)));
    }

    public function destroy(Request $request, string $task): JsonResponse
    {
        $this->tasks->delete($task, $this->actor($request));

        return response()->json(null, 204);
    }

    public function restore(Request $request, string $task): JsonResponse
    {
        return $this->model($this->tasks->restore($task, $this->revision($request), $this->actor($request)));
    }

    public function assign(Request $request, string $task): JsonResponse
    {
        $actor = $this->actor($request);
        $request->validate(['assigneeId' => 'required|string']);

        return $this->model($this->assignments->assign($task, app(TaskPrincipalResolver::class)->resolve($request->input('assigneeId')), $actor), 201);
    }

    public function unassign(Request $request, string $task, string $assignee): JsonResponse
    {
        $actor = $this->actor($request);
        $this->assignments->unassign($task, app(TaskPrincipalResolver::class)->resolve($assignee), $actor);

        return response()->json(null, 204);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $request->validate(['dueSoonDays' => 'sometimes|integer|min:1|max:90']);
        $values = $this->reader->dashboard($this->actor($request), $request->has('dueSoonDays') ? (int) $request->input('dueSoonDays') : null);
        $data = [];
        foreach ($values as $key => $value) {
            $data[Str::camel($key)] = $value;
        }

        return response()->json(['data' => $data]);
    }

    public function detail(Request $request, string $task): JsonResponse
    {
        return response()->json(['data' => TaskProjection::detail($this->reader->get($task, $this->actor($request)))]);
    }

    public function checklistItems(Request $request, string $task): JsonResponse
    {
        return $this->page($this->reader->checklist($task, $this->actor($request), (int) $request->input('per_page', 25), (int) $request->input('page', 1)));
    }

    public function timeEntries(Request $request, string $task): JsonResponse
    {
        return $this->page($this->reader->timeEntries($task, $this->actor($request), (int) $request->input('per_page', 25), (int) $request->input('page', 1)));
    }

    public function addChecklistItem(Request $request, string $task): JsonResponse
    {
        return $this->model($this->checklist->add($task, $request->input('title'), $this->revision($request), $this->actor($request)), 201);
    }

    public function updateChecklistItem(Request $request, string $task, string $item): JsonResponse
    {
        return $this->model($this->checklist->update($task, $item, $request->input('title'), $this->revision($request), $this->actor($request)), 200);
    }

    public function toggleChecklistItem(Request $request, string $task, string $item): JsonResponse
    {
        return $this->model($this->checklist->toggle($task, $item, $request->boolean('completed'), $this->revision($request), $this->actor($request)), 200);
    }

    public function reorderChecklistItems(Request $request, string $task): JsonResponse
    {
        return $this->model($this->checklist->reorder($task, $request->input('itemIds'), $this->revision($request), $this->actor($request)), 200);
    }

    public function removeChecklistItem(Request $request, string $task, string $item): JsonResponse
    {
        $this->checklist->remove($task, $item, $this->revision($request), $this->actor($request));

        return response()->json(null, 204);
    }

    public function addTag(Request $request, string $task): JsonResponse
    {
        return $this->model($this->tags->add($task, $request->input('tag'), $this->revision($request), $this->actor($request)), 201);
    }

    public function removeTag(Request $request, string $task, string $tag): JsonResponse
    {
        $this->tags->remove($task, $tag, $this->revision($request), $this->actor($request));

        return response()->json(null, 204);
    }

    public function addTimeEntry(Request $request, string $task): JsonResponse
    {
        return $this->model($this->time->add($task, $this->attributes($request->except('expected_revision')), $this->revision($request), $this->actor($request)), 201);
    }

    public function updateTimeEntry(Request $request, string $task, string $entry): JsonResponse
    {
        return $this->model($this->time->update($task, $entry, $this->attributes($request->except('expected_revision')), $this->revision($request), $this->actor($request)), 200);
    }

    public function deleteTimeEntry(Request $request, string $task, string $entry): JsonResponse
    {
        $this->time->delete($task, $entry, $this->revision($request), $this->actor($request));

        return response()->json(null, 204);
    }

    public function startTimer(Request $request, string $task): JsonResponse
    {
        return $this->model($this->time->startTimer($task, $this->revision($request), $this->actor($request)), 201);
    }

    public function stopTimer(Request $request, string $task, string $entry): JsonResponse
    {
        return $this->model($this->time->stopTimer($task, $entry, $this->revision($request), $this->actor($request)), 200);
    }

    public function linkParent(Request $request, string $task, string $related): JsonResponse
    {
        return $this->model($this->relationships->linkParent($task, $related, $this->revision($request, 'expectedTaskRevision'), $this->revision($request, 'expectedRelatedRevision'), $this->actor($request)), 200);
    }

    public function unlinkParent(Request $request, string $task, string $related): JsonResponse
    {
        $this->relationships->unlinkParent($task, $related, $this->revision($request, 'expectedTaskRevision'), $this->revision($request, 'expectedRelatedRevision'), $this->actor($request));

        return response()->json(null, 204);
    }

    public function addBlocker(Request $request, string $task, string $related): JsonResponse
    {
        return $this->model($this->relationships->addDependency($task, $related, $this->revision($request, 'expectedTaskRevision'), $this->revision($request, 'expectedRelatedRevision'), $this->actor($request)), 201);
    }

    public function removeBlocker(Request $request, string $task, string $related): JsonResponse
    {
        $this->relationships->removeDependency($task, $related, $this->revision($request, 'expectedTaskRevision'), $this->revision($request, 'expectedRelatedRevision'), $this->actor($request));

        return response()->json(null, 204);
    }

    private function actor(Request $request): Authenticatable
    {
        return $request->user() ?? abort(401);
    }

    private function revision(Request $request, string $field = 'expected_revision'): int
    {
        $request->validate([$field => 'required|integer|min:1']);

        return (int) $request->input($field);
    }

    private function attributes(array $input): array
    {
        $attributes = [];
        foreach ($input as $key => $value) {
            $attributes[Str::snake($key)] = $value;
        }

        return $attributes;
    }

    private function model(Model $model, int $status = 200): JsonResponse
    {
        return response()->json(['data' => TaskProjection::model($model)], $status);
    }

    private function page(LengthAwarePaginator $page): JsonResponse
    {
        return response()->json(['data' => array_map(TaskProjection::model(...), $page->items()), 'meta' => ['total' => $page->total()]]);
    }
}
