<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Nvl\Ownership\Services\OwnerIdentityResolver;
use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\ValueObjects\OwnershipMapping;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;
use Nvl\Tasks\Services\TaskReader;
use Nvl\Tasks\Tests\Fixtures\Ownership\HistoricalTasks;
use Nvl\Tasks\Tests\OutboxAdoptionTestCase;

uses(OutboxAdoptionTestCase::class);

it('adopts populated native tasks and every child while conserving historical outbox bytes and reviewed owners', function (): void {
    $this->allowOwners();
    $history = HistoricalTasks::seed();
    $resources = app(OwnershipResourceRegistry::class);
    foreach (array_unique(array_column($history['records'], 'resource')) as $resource) {
        $model = new ($resources->get($resource)->model);
        expect($model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), 'owner_scope_key'))->toBeFalse();
    }
    $coordinator = app(OwnershipAdoptionCoordinator::class);
    $operation = new OwnershipOperation('populated-tasks', 'operator', '1');
    $plan = $coordinator->prepare(['tasks'], $history['mappings'], $operation);
    $done = false;
    for ($batch = 0; $batch < 30 && ! $done; $batch++) {
        $done = $coordinator->backfill($plan, 3, $operation);
    }
    expect($done)->toBeTrue()->and($batch)->toBeGreaterThan(1);
    $verification = $coordinator->verify($plan);
    expect($verification->errors)->toBe([])->and($verification->passed())->toBeTrue();
    $coordinator->activate($plan, $operation);
    app(MaintenanceMode::class)->deactivate();
    foreach ($history['records'] as $record) {
        $model = new ($resources->get($record['resource'])->model);
        $row = $model->getConnection()->table($model->getTable())->where('id', $record['id'])->first();
        $reference = app(OwnerIdentityResolver::class)->reference(Company::query()->findOrFail($record['owner']));
        expect($row->owner_scope_key)->toBe($reference->scopeKey());
        foreach ($record['facts'] as $field => $value) {
            expect($row->{$field})->toBe($value);
        }
        if ($record['resource'] === 'tasks.activity_outbox') {
            expect(OwnerReference::fromArray(json_decode($row->owner_reference, true, flags: JSON_THROW_ON_ERROR))->toArray())->toBe($reference->toArray())
                ->and($row->payload)->toBe($history['payloads'][$record['id']]);
        }
    }
    foreach ([7, 8] as $owner) {
        app(OwnershipRunner::class)->run(Company::query()->findOrFail($owner), Actor::query()->findOrFail(1), function () use ($resources, $history, $owner): void {
            foreach (array_unique(array_column($history['records'], 'resource')) as $resource) {
                $class = $resources->get($resource)->model;
                $expected = array_values(array_column(array_filter($history['records'], fn (array $record): bool => $record['resource'] === $resource && $record['owner'] === $owner), 'id'));
                expect($class::query()->pluck('id')->all())->toEqualCanonicalizing($expected);
            }
            expect(app(TaskReader::class)->paginate([], Actor::query()->findOrFail(1))->total())->toBe(2);
        });
    }
});

it('rejects incomplete or inconsistent reviewed historical mappings without discarding payloads', function (string $case): void {
    $this->allowOwners();
    $history = HistoricalTasks::seed();
    $mappings = $history['mappings'];
    $resource = match ($case) {
        'missing child', 'conflicting child' => 'tasks.checklist_items',
        'conflicting native owner' => 'tasks.tasks',
        default => 'tasks.activity_outbox',
    };
    $index = array_find_key($mappings, fn (OwnershipMapping $mapping): bool => $mapping->resource === $resource);
    $mapping = $mappings[$index];
    if (str_starts_with($case, 'missing')) {
        unset($mappings[$index]);
    } else {
        $other = app(OwnerIdentityResolver::class)->reference(Company::query()->findOrFail(8));
        $replacement = new OwnershipMapping($mapping->resource, $mapping->recordId, $other, legacyTenantId: $mapping->legacyTenantId);
        if ($case === 'duplicate conflicting outbox') {
            $mappings[] = $replacement;
        } else {
            $mappings[$index] = $replacement;
        }
    }
    $coordinator = app(OwnershipAdoptionCoordinator::class);
    $operation = new OwnershipOperation('invalid-history', 'operator', '1');
    $error = null;
    try {
        $plan = $coordinator->prepare(['tasks'], $mappings, $operation);
        $done = false;
        for ($batch = 0; $batch < 30 && ! $done; $batch++) {
            $done = $coordinator->backfill($plan, 3, $operation);
        }
        expect($done)->toBeTrue();
        $coordinator->activate($plan, $operation);
    } catch (OwnershipConfigurationInvalid|OwnershipSchemaNotReady|OwnershipViolation $caught) {
        $error = $caught;
    }
    expect($error)->not->toBeNull();
    $message = match ($case) {
        'missing child', 'missing outbox' => 'Every adopted record requires an explicit canonical mapping.',
        'conflicting native owner' => 'Tasks adoption cannot relabel an existing native primary owner.',
        'conflicting child' => 'The persisted full owner and its partition key differ.',
        'duplicate conflicting outbox' => 'Duplicate or conflicting record assignment.',
    };
    expect($error->getMessage())->toContain($message);
    $model = new (app(OwnershipResourceRegistry::class)->get('tasks.activity_outbox')->model);
    expect($model->getConnection()->table($model->getTable())->count())->toBe(6);
    foreach ($history['payloads'] as $id => $payload) {
        expect($model->getConnection()->table($model->getTable())->where('id', $id)->value('payload'))->toBe($payload);
    }
})->with(['missing child', 'missing outbox', 'conflicting native owner', 'conflicting child', 'duplicate conflicting outbox']);
