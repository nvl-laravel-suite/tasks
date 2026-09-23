<?php

declare(strict_types=1);

namespace Nvl\Tasks\Tests;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Nvl\Content\Providers\ContentServiceProvider;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Filterable\Providers\FilterableServiceProvider;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Metafields\Providers\MetafieldsServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Tasks\Providers\TasksServiceProvider;
use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Exceptions\TenantNotFound;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Tenancy\ValueObjects\TenantId;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/** Boots the adopted Tasks dependency graph without the suite umbrella. */
abstract class TenancyTestCase extends Orchestra
{
    use DatabaseMigrations;

    public const string TENANT_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    public const string TENANT_B = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            SupportServiceProvider::class,
            DataServiceProvider::class,
            TenancyServiceProvider::class,
            TranslatableServiceProvider::class,
            FilterableServiceProvider::class,
            MediaServiceProvider::class,
            ContentServiceProvider::class,
            MetafieldsServiceProvider::class,
            TasksServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'app.key' => 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=',
            'cache.default' => 'array',
            'filesystems.default' => 'local',
            'media.disk' => 'local',
            'media.routes.assets_enabled' => false,
            'content.authorization.callback' => static fn (): bool => true,
            'translatable.locales' => ['en'],
            'translatable.fallback_locales' => ['en'],
            'tenancy.enabled' => true,
            'tenancy.migrations.enabled' => true,
            'tenancy.profile' => 'application',
            'tenancy.resources' => [
                'media' => 'tenant',
                'content' => 'tenant',
                'metafields' => 'tenant',
                'tasks' => 'tenant',
            ],
            'tenancy.sharing' => ['media' => 'none', 'metafields' => 'none', 'templates' => 'none'],
        ]);

        $app->instance(TenantDirectory::class, new class implements TenantDirectory
        {
            public function find(TenantId $tenant): TenantDescriptor
            {
                if (! in_array($tenant->value, [TenancyTestCase::TENANT_A, TenancyTestCase::TENANT_B], true)) {
                    throw new TenantNotFound('Unknown Tasks test tenant.');
                }

                return new TenantDescriptor($tenant, TenantStatus::Active);
            }
        });
        $app->instance(PlatformAccess::class, new class implements PlatformAccess
        {
            public function authorize(PlatformOperation $operation): void {}
        });
        $app->instance(MaintenanceMode::class, new class implements MaintenanceMode
        {
            private bool $enabled = true;

            /** @param array<string, mixed> $payload */
            public function activate(array $payload): void
            {
                $this->enabled = true;
            }

            public function deactivate(): void
            {
                $this->enabled = false;
            }

            public function active(): bool
            {
                return $this->enabled;
            }

            /** @return array<string, mixed> */
            public function data(): array
            {
                return [];
            }
        });
    }
}
