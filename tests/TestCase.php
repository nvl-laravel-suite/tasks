<?php

declare(strict_types=1);

namespace Nvl\Tasks\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Nvl\Content\Providers\ContentServiceProvider;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Filterable\Providers\FilterableServiceProvider;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Metafields\Providers\MetafieldsServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Tasks\Providers\TasksServiceProvider;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/** Boots Tasks against its declared dependency graph with an isolated database. */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            SupportServiceProvider::class,
            DataServiceProvider::class,
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
            'cache.default' => 'array',
            'filesystems.default' => 'local',
            'media.disk' => 'local',
            'media.routes.assets_enabled' => false,
            'content.authorization.callback' => static fn (): bool => true,
            'translatable.locales' => ['en'],
            'translatable.fallback_locales' => ['en'],
        ]);
    }

    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }
}
