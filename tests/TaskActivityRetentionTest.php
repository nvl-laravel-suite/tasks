<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\Event;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Models\TaskActivityOutbox;
use Nvl\Tasks\Services\TaskLifecycle;
use Nvl\Tasks\Services\TasksActivityRetention;
use Nvl\Tasks\Tests\CommittedTasksTestCase;

uses(CommittedTasksTestCase::class);

it('rejects a last-batch completion source change while preserving its real committed deletion', function (): void {
    config(['nvl-tasks.activity.retention.enabled' => true, 'nvl-tasks.activity.retention.days' => 30, 'nvl-tasks.activity.retention.maximum_records' => 1, 'nvl-tasks.activity.retention.batch_size' => 1]);
    $task = app(TaskLifecycle::class)->create(['title' => 'Committed retained fact'], SystemActor::Background);
    retainedTaskFact($task, CarbonImmutable::now('UTC')->subDays(60));
    $source = $task->getConnection();
    $pdo = $source->getPdo();
    $replacement = new PDO('sqlite::memory:');
    $deleted = false;
    $table = $source->getQueryGrammar()->wrapTable((new TaskActivityOutbox)->getTable());
    $source->listen(static function (QueryExecuted $query) use (&$deleted, $table): void {
        if (str_starts_with(strtolower($query->sql), 'delete ') && str_contains($query->sql, $table)) {
            $deleted = true;
        }
    });
    $switched = false;
    Event::listen(TransactionCommitted::class, static function (TransactionCommitted $event) use ($source, $replacement, &$deleted, &$switched): void {
        if ($deleted && ! $switched && $event->connection === $source && $source->transactionLevel() === 0) {
            $switched = true;
            $source->setPdo($replacement);
        }
    });

    try {
        expect(fn () => app(TasksActivityRetention::class)->prune(SystemActor::Background))->toThrow(OwnershipViolation::class, 'source changed');
        expect($source->getRawPdo())->toBe($pdo)->and($pdo->inTransaction())->toBeFalse()
            ->and($source->transactionLevel())->toBe(0)->and($replacement->inTransaction())->toBeFalse();
    } finally {
        $source->setPdo($pdo);
    }

    expect($deleted)->toBeTrue()->and($switched)->toBeTrue()->and(TaskActivityOutbox::query()->count())->toBe(0);
});

beforeEach(function (): void {
    $driver = getenv('NVL_C1_TEST_DRIVER');
    if (in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
        $source = (new TaskActivityOutbox)->getConnection();
        expect($source->getDriverName())->toBe($driver)
            ->and($source->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME))->toBe($driver === 'mariadb' ? 'mysql' : $driver);
    }
});

function retainedTaskFact(Task $task, ?CarbonImmutable $delivered): TaskActivityOutbox
{
    $outbox = new TaskActivityOutbox;
    $outbox->forceFill(['task_id' => $task->id, 'payload' => ['event' => 'retention.fixture'], 'available_at' => CarbonImmutable::now('UTC')->subDays(60), 'delivered_at' => $delivered])->save();

    return $outbox;
}

it('requires explicit outbox retention enablement and positive ages', function (string $missing): void {
    config(['nvl-tasks.activity.retention.enabled' => $missing !== 'enabled', 'nvl-tasks.activity.retention.days' => $missing === 'days' ? null : 30]);
    $task = app(TaskLifecycle::class)->create(['title' => 'Retained fact'], SystemActor::Background);
    $fact = retainedTaskFact($task, CarbonImmutable::now('UTC')->subDays(60));

    expect(fn () => app(TasksActivityRetention::class)->prune(SystemActor::Background))->toThrow(InvalidArgumentException::class)
        ->and($fact->fresh())->not->toBeNull();
})->with(['enabled', 'days']);

it('previews and deletes only bounded old delivered facts while preserving pending and recent delivery', function (): void {
    config(['nvl-tasks.activity.retention.enabled' => true, 'nvl-tasks.activity.retention.days' => 30, 'nvl-tasks.activity.retention.maximum_records' => 2, 'nvl-tasks.activity.retention.batch_size' => 1]);
    $task = app(TaskLifecycle::class)->create(['title' => 'Retained fact'], SystemActor::Background);
    foreach (range(1, 3) as $number) {
        retainedTaskFact($task, CarbonImmutable::now('UTC')->subDays(60));
    }
    $pending = retainedTaskFact($task, null);
    $recent = retainedTaskFact($task, CarbonImmutable::now('UTC'));
    $retention = app(TasksActivityRetention::class);

    expect($retention->prune(SystemActor::Background, true))->toBe(['matched' => 2, 'deleted' => 0, 'has_more' => true])
        ->and(TaskActivityOutbox::query()->count())->toBe(5);
    expect($retention->prune(SystemActor::Background))->toBe(['matched' => 2, 'deleted' => 2, 'has_more' => true])
        ->and(TaskActivityOutbox::query()->count())->toBe(3)
        ->and($pending->fresh())->not->toBeNull()->and($recent->fresh())->not->toBeNull();
});

it('requires the explicit maintenance ability even for a deliberately supplied system actor', function (): void {
    config(['nvl-tasks.activity.retention.enabled' => true, 'nvl-tasks.activity.retention.days' => 30]);
    $task = app(TaskLifecycle::class)->create(['title' => 'Retained fact'], SystemActor::Background);
    $fact = retainedTaskFact($task, CarbonImmutable::now('UTC')->subDays(60));
    app()->instance(TaskAuthorization::class, new class implements TaskAuthorization
    {
        public function authorize(TaskAbility $ability, Authenticatable|SystemActor $actor, ?Task $task = null, ?Model $subject = null): void
        {
            expect($ability)->toBe(TaskAbility::PruneActivity)->and($task)->toBeNull();
            throw new AuthorizationException('Outbox maintenance is not granted.');
        }

        public function scopeTasks(Builder $query, Authenticatable|SystemActor $actor): void {}
    });

    expect(fn () => app(TasksActivityRetention::class)->prune(SystemActor::Background))->toThrow(AuthorizationException::class)
        ->and($fact->fresh())->not->toBeNull();
});

it('retains pending evidence independently of task deletion and prunes only delivered history', function (): void {
    config(['nvl-tasks.activity.retention.enabled' => true, 'nvl-tasks.activity.retention.days' => 30]);
    $task = app(TaskLifecycle::class)->create(['title' => 'Retired task'], SystemActor::Background);
    retainedTaskFact($task, CarbonImmutable::now('UTC')->subDays(60));
    $pending = retainedTaskFact($task, null);
    $task->forceDelete();

    expect(app(TasksActivityRetention::class)->prune(SystemActor::Background))->toBe(['matched' => 1, 'deleted' => 1, 'has_more' => false])
        ->and($pending->fresh())->not->toBeNull();
});

it('has an actual delivered-and-ready leading index without a legacy tenant prefix', function (): void {
    $model = new TaskActivityOutbox;
    $indexes = $model->getConnection()->getSchemaBuilder()->getIndexes($model->getTable());

    expect(collect($indexes)->contains(static fn (array $index): bool => array_slice($index['columns'], 0, 2) === ['delivered_at', 'available_at']))->toBeTrue();
});

it('repeats the fixed delivered predicate after current host authorization', function (): void {
    config(['nvl-tasks.activity.retention.enabled' => true, 'nvl-tasks.activity.retention.days' => 30]);
    $task = app(TaskLifecycle::class)->create(['title' => 'Reset delivery'], SystemActor::Background);
    $fact = retainedTaskFact($task, CarbonImmutable::now('UTC')->subDays(60));
    app()->instance(TaskAuthorization::class, new class($fact) implements TaskAuthorization
    {
        private int $calls = 0;

        public function __construct(private TaskActivityOutbox $fact) {}

        public function authorize(TaskAbility $ability, Authenticatable|SystemActor $actor, ?Task $task = null, ?Model $subject = null): void
        {
            if (++$this->calls === 3) {
                $this->fact->forceFill(['delivered_at' => null])->save();
            }
        }

        public function scopeTasks(Builder $query, Authenticatable|SystemActor $actor): void {}
    });

    expect(app(TasksActivityRetention::class)->prune(SystemActor::Background))->toBe(['matched' => 1, 'deleted' => 0, 'has_more' => false])
        ->and($fact->fresh()->delivered_at)->toBeNull();
});

it('keeps completed batches when later maintenance authority is revoked', function (): void {
    config(['nvl-tasks.activity.retention.enabled' => true, 'nvl-tasks.activity.retention.days' => 30, 'nvl-tasks.activity.retention.batch_size' => 1]);
    $task = app(TaskLifecycle::class)->create(['title' => 'Revoked maintenance'], SystemActor::Background);
    retainedTaskFact($task, CarbonImmutable::now('UTC')->subDays(60));
    retainedTaskFact($task, CarbonImmutable::now('UTC')->subDays(60));
    app()->instance(TaskAuthorization::class, new class implements TaskAuthorization
    {
        private int $calls = 0;

        public function authorize(TaskAbility $ability, Authenticatable|SystemActor $actor, ?Task $task = null, ?Model $subject = null): void
        {
            if (++$this->calls === 4) {
                throw new AuthorizationException('Maintenance authority was revoked.');
            }
        }

        public function scopeTasks(Builder $query, Authenticatable|SystemActor $actor): void {}
    });

    expect(fn () => app(TasksActivityRetention::class)->prune(SystemActor::Background))->toThrow(AuthorizationException::class)
        ->and(TaskActivityOutbox::query()->count())->toBe(1);
});

it('rejects host transactions even for previews without rolling back host work', function (bool $dryRun): void {
    config(['nvl-tasks.activity.retention.enabled' => true, 'nvl-tasks.activity.retention.days' => 30]);
    $source = (new TaskActivityOutbox)->getConnection();
    $source->beginTransaction();
    try {
        expect(fn () => app(TasksActivityRetention::class)->prune(SystemActor::Background, $dryRun))->toThrow(InvalidArgumentException::class, 'outside host transactions')
            ->and($source->transactionLevel())->toBe(1)->and($source->getPdo()->inTransaction())->toBeTrue();
    } finally {
        $source->rollBack();
    }
})->with([false, true]);

it('fences policy writer replacement and rolls back only its captured transaction', function (bool $throws): void {
    config(['nvl-tasks.activity.retention.enabled' => true, 'nvl-tasks.activity.retention.days' => 30]);
    $task = app(TaskLifecycle::class)->create(['title' => 'Frozen source'], SystemActor::Background);
    $fact = retainedTaskFact($task, CarbonImmutable::now('UTC')->subDays(60));
    $source = $fact->getConnection();
    $pdo = $source->getPdo();
    $replacement = new PDO('sqlite::memory:');
    $replacement->exec('CREATE TABLE retained_evidence (id INTEGER PRIMARY KEY)');
    $replacement->exec('INSERT INTO retained_evidence(id) VALUES (1)');
    $replacement->beginTransaction();
    $committed = 0;
    app()->instance(TaskAuthorization::class, new class($fact, $replacement, $committed, $throws) implements TaskAuthorization
    {
        private int $calls = 0;

        public function __construct(private TaskActivityOutbox $fact, private PDO $replacement, private int &$committed, private bool $throws) {}

        public function authorize(TaskAbility $ability, Authenticatable|SystemActor $actor, ?Task $task = null, ?Model $subject = null): void
        {
            if (++$this->calls === 3) {
                $source = $this->fact->getConnection();
                $source->table($this->fact->getTable())->where('id', $this->fact->id)->update(['last_error' => 'Must roll back']);
                $source->afterCommit(function (): void {
                    $this->committed++;
                });
                $source->setPdo($this->replacement);
                if ($this->throws) {
                    throw new RuntimeException('Policy failed after replacing its writer.');
                }
            }
        }

        public function scopeTasks(Builder $query, Authenticatable|SystemActor $actor): void {}
    });
    try {
        expect(fn () => app(TasksActivityRetention::class)->prune(SystemActor::Background))->toThrow(OwnershipViolation::class, 'source changed')
            ->and($pdo->inTransaction())->toBeFalse()->and($source->transactionLevel())->toBe(0)
            ->and($fact->fresh()->last_error)->toBeNull()->and($replacement->inTransaction())->toBeTrue()
            ->and($replacement->query('SELECT COUNT(*) FROM retained_evidence')->fetchColumn())->toBe(1);
        $source->transaction(static fn (): null => null);
        expect($committed)->toBe(0);
    } finally {
        $source->setPdo($pdo);
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($replacement->inTransaction()) {
            $replacement->rollBack();
        }
    }
})->with([false, true]);
