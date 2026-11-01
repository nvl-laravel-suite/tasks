<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Tasks\Definitions\Tables\TasksTables;
use Nvl\Tasks\Tests\OutboxAdoptionTestCase;

uses(OutboxAdoptionTestCase::class);

it('blocks adoption when an orphan historical outbox lacks an explicit reviewed native owner mapping', function (): void {
    $id = (string) Str::uuid();
    $payload = json_encode(['historical' => 'retained'], JSON_THROW_ON_ERROR);
    $table = TasksTables::get(TasksTables::ActivityOutbox);
    DB::table($table)->insert(['id' => $id, 'task_id' => (string) Str::uuid(), 'payload' => $payload, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $payload = DB::table($table)->where('id', $id)->value('payload');
    $operation = new OwnershipOperation('orphan-proof', 'operator', '1');
    $coordinator = app(OwnershipAdoptionCoordinator::class);
    expect(function () use ($coordinator, $operation): void {
        $plan = $coordinator->prepare(['tasks'], [], $operation);
        while (! $coordinator->backfill($plan, 100, $operation)) {
        }
        $coordinator->activate($plan, $operation);
    })->toThrow(OwnershipConfigurationInvalid::class);
    expect(DB::table($table)->where('id', $id)->value('payload'))->toBe($payload)
        ->and(DB::table($table)->where('id', $id)->count())->toBe(1);
});
