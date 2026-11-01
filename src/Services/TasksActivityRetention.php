<?php

declare(strict_types=1);

namespace Nvl\Tasks\Services;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Models\TaskActivityOutbox;
use PDO;

/**
 * Prunes delivered task facts under explicit host maintenance authority.
 *
 * @phpstan-type RetentionSource array{connection: Connection, pdo: PDO, read: PDO|Closure|null, direct: PDO|Closure|null, table: string, scope: string}
 *
 * @api
 */
final readonly class TasksActivityRetention
{
    public function __construct(private TaskAuthorization $authorization, private TaskPrincipalGuard $principals, private OwnershipBoundary $boundary) {}

    /**
     * Operate only within the currently admitted owner/source, including retired tasks.
     * No age or schedule is inferred. Both previews and writes require no host transaction.
     * Completed batches remain committed if a later batch loses authority.
     *
     * @return array{matched: int, deleted: int, has_more: bool}
     */
    public function prune(Authenticatable|SystemActor $actor, bool $dryRun = false): array
    {
        $this->assertEnabled();
        $days = config('nvl-tasks.activity.retention.days');
        if (! is_int($days) || $days < 1 || $days > 36500) {
            throw new InvalidArgumentException('Task activity retention requires an explicit positive age no greater than 36,500 days.');
        }
        $remaining = $this->budget('maximum_records', 1000, 10000);
        $batchSize = $this->budget('batch_size', 200, 1000);
        $model = new TaskActivityOutbox;
        $connection = $model->getConnection();
        $source = ['connection' => $connection, 'pdo' => $connection->getPdo(), 'read' => $connection->getRawReadPdo(), 'direct' => $connection->getRawDirectPdo(), 'table' => $model->getTable(), 'scope' => ''];
        if ($connection->transactionLevel() !== 0 || $source['pdo']->inTransaction()) {
            throw new InvalidArgumentException('Run Task activity retention and its dry previews outside host transactions.');
        }
        try {
            $source['scope'] = $this->boundary->key(TaskActivityOutbox::TENANT_RESOURCE, 'tasks.activity-retention');
        } finally {
            $this->assertSource($source);
        }
        $actor = $this->authorize($actor, $source);
        $cutoff = CarbonImmutable::now('UTC')->subDays($days);
        $after = null;
        $result = ['matched' => 0, 'deleted' => 0, 'has_more' => false];
        do {
            $this->assertSource($source);
            $take = min($remaining, $batchSize);
            $batch = $connection->transaction(function () use ($actor, $source, $cutoff, $after, $take, $dryRun): array {
                try {
                    $actor = $this->authorize($actor, $source);
                    $query = $this->query($cutoff);
                    if ($after !== null) {
                        $query->where($query->getModel()->getQualifiedKeyName(), '>', $after);
                    }
                    $this->assertSource($source);
                    $identifiers = $query->orderBy($query->getModel()->getQualifiedKeyName())->limit($take + 1)->lockForUpdate()->pluck('id')->all();
                    $more = count($identifiers) > $take;
                    $selected = array_slice($identifiers, 0, $take);
                    foreach ($selected as $identifier) {
                        if (! is_string($identifier) || $identifier === '') {
                            throw new InvalidArgumentException('Task activity retention requires native scalar record identities.');
                        }
                    }
                    if ($selected === []) {
                        return ['matched' => 0, 'deleted' => 0, 'more' => false, 'after' => null];
                    }
                    $this->authorize($actor, $source);
                    $deletions = $this->query($cutoff)->whereKey($selected);
                    $this->assertSource($source);
                    $deleted = $dryRun ? 0 : $deletions->delete();
                    if (! is_int($deleted)) {
                        throw new InvalidArgumentException('Task activity retention requires an exact native deletion count.');
                    }

                    return ['matched' => count($selected), 'deleted' => $deleted, 'more' => $more, 'after' => $selected[array_key_last($selected)]];
                } finally {
                    $this->assertSource($source);
                }
            }, 3);
            $this->assertSource($source);
            $result['matched'] += $batch['matched'];
            $result['deleted'] += $batch['deleted'];
            $remaining -= $batch['matched'];
            $after = $batch['after'];
            $result['has_more'] = $batch['more'];
        } while ($batch['more'] && $remaining > 0);

        return $result;
    }

    /** @return Builder<TaskActivityOutbox> */
    private function query(CarbonImmutable $cutoff): Builder
    {
        return $this->boundary->query(TaskActivityOutbox::query()->useWritePdo(), TaskActivityOutbox::TENANT_RESOURCE)->where('delivered_at', '<', $cutoff);
    }

    /** @param RetentionSource $source */
    private function authorize(Authenticatable|SystemActor $actor, array $source): Authenticatable|SystemActor
    {
        $this->assertSource($source);
        try {
            $this->assertEnabled();
            $actor = $this->principals->actor($actor);
            $this->assertSource($source);
            $this->authorization->authorize(TaskAbility::PruneActivity, $actor);
            $this->assertSource($source);
            if ($this->boundary->key(TaskActivityOutbox::TENANT_RESOURCE, 'tasks.activity-retention') !== $source['scope']) {
                throw new OwnershipViolation('The admitted Task activity retention scope changed.');
            }

            return $actor;
        } finally {
            $this->assertSource($source);
        }
    }

    /** @param RetentionSource $source */
    private function assertSource(array $source): void
    {
        if ($source['connection']->getRawPdo() !== $source['pdo']) {
            try {
                if ($source['pdo']->inTransaction()) {
                    $source['pdo']->rollBack();
                }
            } finally {
                $source['connection']->setPdo($source['pdo']);
                try {
                    $source['connection']->disconnect();
                } finally {
                    $source['connection']->setPdo($source['pdo'])->setReadPdo($source['read'])->setDirectPdo($source['direct']);
                }
            }
            throw new OwnershipViolation('The Task activity retention source changed; retention stopped.');
        }
        $model = new TaskActivityOutbox;
        if ($model->getConnection() !== $source['connection'] || $model->getTable() !== $source['table']) {
            throw new OwnershipViolation('The Task activity retention source changed.');
        }
    }

    private function assertEnabled(): void
    {
        if (config('nvl-tasks.activity.retention.enabled') !== true) {
            throw new InvalidArgumentException('Task activity retention is disabled until explicitly configured.');
        }
    }

    private function budget(string $key, int $default, int $maximum): int
    {
        $value = config('nvl-tasks.activity.retention.'.$key, $default);
        if (! is_int($value) || $value < 1 || $value > $maximum) {
            throw new InvalidArgumentException('Task activity retention budgets must be positive bounded integers.');
        }

        return $value;
    }
}
