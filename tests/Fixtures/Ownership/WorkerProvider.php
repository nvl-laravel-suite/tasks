<?php

declare(strict_types=1);

namespace Nvl\Tasks\Tests\Fixtures\Ownership;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Nvl\Ownership\Contracts\OwnerAccess;
use Nvl\Ownership\Contracts\OwnerAvailability;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Tasks\Enums\TaskChangeOperation;
use Nvl\Tasks\Tests\Fixtures\OwnedTask;

/** Boots the immutable Tasks and remote audit graph in a separate real worker. */
final class WorkerProvider extends ServiceProvider
{
    /** @var array<string, mixed> */
    private static array $sources = [];

    /** @param array<string,mixed> $sources */
    public static function useSources(array $sources): void
    {
        self::$sources = $sources;
    }

    /** Configure existing dedicated sources without installing or adopting anything. */
    public function register(): void
    {
        $this->app->make('config')->set([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => self::$sources['primary'],
            'database.connections.ownership_remote' => self::$sources['remote'],
            'nvl-core.owners' => [Company::class, Actor::class],
            'nvl-ownership.enabled' => true,
            'nvl-ownership.migrations.enabled' => false,
            'nvl-tenancy.enabled' => false,
            'nvl-tasks.models.task' => OwnedTask::class,
            'nvl-tasks.migrations.enabled' => false,
            'nvl-tasks.activity.enabled' => true,
            'nvl-activity.events' => array_fill_keys(array_column(TaskChangeOperation::cases(), 'value'), ['change_fields' => null, 'metadata_fields' => null]),
            'nvl-tasks.media.enabled' => false,
            'nvl-activity.connection' => 'ownership_remote',
            'nvl-activity.migrations.enabled' => false,
            'queue.default' => 'database',
            'queue.connections.database.connection' => 'sqlite',
            'queue.failed' => ['driver' => 'database-uuids', 'database' => 'sqlite', 'table' => 'failed_jobs'],
            'cache.default' => 'array',
        ]);
        $this->app->bind(OwnerAccess::class, static fn () => new class implements OwnerAccess
        {
            public function assertCanEnter(Authenticatable $actor, Model $owner): void
            {
                if (! $actor instanceof Actor || $actor->getKey() !== 1) {
                    throw new OwnershipViolation;
                }
            }
        });
        $this->app->bind(OwnerAvailability::class, static fn () => new class implements OwnerAvailability
        {
            public function assertAvailable(Model $owner): void
            {
                if (! $owner instanceof Company || $owner->getAttribute('active') !== true) {
                    throw new OwnershipViolation;
                }
            }
        });
    }

    /** Record the scope after the native handler restores its prior context. */
    public function boot(): void
    {
        $this->app->make('events')->listen(JobProcessed::class, static function (): void {
            DB::table('ownership_queue_probes')->insert(['stage' => 'task-worker-after', 'owner' => app(OwnershipContext::class)->snapshot()->mode->value, 'label' => 'worker']);
        });
    }
}
