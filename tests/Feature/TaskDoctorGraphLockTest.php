<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Nvl\Tasks\Definitions\Tables\TasksTables;
use Nvl\Tasks\Services\TasksDoctor;
use Nvl\Tasks\Support\TasksConfiguration;

it('reports a present graph lock table whose serialization row is missing', function (): void {
    expect(app(TasksDoctor::class)->inspect()['checks']['graph_lock.row'])->toBeTrue();

    DB::connection(TasksConfiguration::connection())->table(TasksTables::get(TasksTables::GraphLock))->delete();
    $result = app(TasksDoctor::class)->inspect();

    expect($result['healthy'])->toBeFalse()
        ->and($result['checks']['graph_lock.table'])->toBeTrue()
        ->and($result['checks']['graph_lock.row'])->toBeFalse();
});
