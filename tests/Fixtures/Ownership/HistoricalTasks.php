<?php

declare(strict_types=1);

namespace Nvl\Tasks\Tests\Fixtures\Ownership;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Nvl\Activity\Support\ActivityRecordEnvelope;
use Nvl\Activity\Support\ActivitySubjectReference;
use Nvl\Ownership\Services\OwnerIdentityResolver;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\ValueObjects\OwnershipMapping;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Tasks\Models\Task;

/** Populate native-schema history before any ownership prepare/backfill/activation. */
final class HistoricalTasks
{
    /** @return array{records: list<array{resource: string, id: string, owner: int, facts: array<string, mixed>}>, mappings: list<OwnershipMapping>, roots: array<int, list<string>>, payloads: array<string, string>} */
    public static function seed(): array
    {
        $records = $mappings = $roots = $payloads = [];
        $legacy = (string) Str::uuid();
        $resources = app(OwnershipResourceRegistry::class);
        $insert = function (string $resource, array $attributes, int $owner) use (&$records, &$mappings, &$payloads, $legacy, $resources): string {
            $model = new ($resources->get($resource)->model);
            $id = $attributes['id'] ?? (string) Str::uuid();
            $facts = ['id' => $id, 'tenant_id' => $legacy, 'created_at' => '2026-01-02 10:00:00', 'updated_at' => '2026-01-02 10:00:00', ...$attributes];
            $model->getConnection()->table($model->getTable())->insert($facts);
            if ($resource === 'tasks.activity_outbox') {
                $payload = $model->getConnection()->table($model->getTable())->where('id', $id)->value('payload');
                if (! is_string($payload)) {
                    throw new \RuntimeException('Historical outbox fixtures require a persisted JSON payload.');
                }
                $facts['payload'] = $payloads[$id] = $payload;
            }
            $records[] = compact('resource', 'id', 'owner', 'facts');
            $reference = app(OwnerIdentityResolver::class)->reference(Company::query()->findOrFail($owner));
            $mappings[] = new OwnershipMapping($resource, $id, $reference, legacyTenantId: $legacy);

            return $id;
        };
        foreach ([7, 8] as $owner) {
            $root = $insert('tasks.tasks', ['title' => 'Historical '.$owner, 'company_id' => $owner, 'revision' => 9], $owner);
            $parent = $insert('tasks.tasks', ['title' => 'Historical parent '.$owner, 'company_id' => $owner, 'revision' => 4], $owner);
            $roots[$owner] = [$root, $parent];
            $insert('tasks.assignments', ['task_id' => $root, 'assignee_type' => Actor::class, 'assignee_id' => '1', 'assigned_by_type' => Actor::class, 'assigned_by_id' => '1'], $owner);
            $insert('tasks.checklist_items', ['task_id' => $root, 'title' => 'Retained checklist', 'position' => 3, 'completed_at' => '2026-01-02 11:00:00'], $owner);
            $insert('tasks.time_entries', ['task_id' => $root, 'performer_type' => Actor::class, 'performer_id' => '1', 'started_at' => '2026-01-02 10:00:00', 'stopped_at' => '2026-01-02 11:00:00', 'duration_seconds' => 3600], $owner);
            $insert('tasks.tags', ['task_id' => $root, 'tag' => 'historical'], $owner);
            $insert('tasks.relationships', ['child_task_id' => $root, 'parent_task_id' => $parent], $owner);
            $insert('tasks.dependencies', ['task_id' => $root, 'blocker_task_id' => $parent], $owner);
            $deleted = (string) Str::uuid();
            $taskModel = new ($resources->get('tasks.tasks')->model);
            $taskModel->getConnection()->table($taskModel->getTable())->insert(['id' => $deleted, 'title' => 'Deleted source', 'company_id' => $owner]);
            foreach ([$root, $deleted, (string) Str::uuid()] as $source) {
                $fact = new ActivityRecordEnvelope((string) Str::uuid(), new ActivitySubjectReference(Task::class, $source), null, 'historical', 'tasks', CarbonImmutable::parse('2026-01-02T10:00:00Z'), context: ['unchanged' => 'audit'], scalarActorId: 'deleted-actor');
                $payload = json_encode($fact->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $insert('tasks.activity_outbox', ['id' => $fact->id, 'task_id' => $source, 'payload' => $payload, 'available_at' => '2026-01-02 10:00:00'], $owner);
            }
            $taskModel->getConnection()->table($taskModel->getTable())->where('id', $deleted)->delete();
        }

        return compact('records', 'mappings', 'roots', 'payloads');
    }
}
