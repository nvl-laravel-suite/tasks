<?php

declare(strict_types=1);

namespace Nvl\Tasks\Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Nvl\Activity\Providers\ActivityServiceProvider;
use Nvl\Filterable\Providers\FilterableServiceProvider;
use Nvl\Media\Contracts\MediaAuthorization;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Models\Media;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Tasks\Providers\TasksServiceProvider;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/** Boots Tasks against its declared dependency graph with an isolated database. */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        nativeTaskPolicy();
        if (interface_exists(MediaAuthorization::class)) {
            $this->app->bind(MediaAuthorization::class, static fn () => new class implements MediaAuthorization
            {
                public function allows(Authenticatable|SystemActor $actor, MediaAbility $ability, ?Media $media = null, ?Model $owner = null): bool
                {
                    return $actor instanceof SystemActor;
                }
            });
        }
        $this->app->forgetScopedInstances();
    }

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            LocaleServiceProvider::class,
            SupportServiceProvider::class,

            ...(class_exists(TranslatableServiceProvider::class) ? [TranslatableServiceProvider::class] : []),
            ...(class_exists(FilterableServiceProvider::class) ? [FilterableServiceProvider::class] : []),
            ...(class_exists(ActivityServiceProvider::class) ? [ActivityServiceProvider::class] : []),
            MediaServiceProvider::class,
            TasksServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'cache.default' => 'array',
            'nvl-tasks.activity.enabled' => true,
            'filesystems.default' => 'local',
            'nvl-media.disk' => 'local',
            'nvl-media.routes.assets_enabled' => false,
            'nvl-translatable.locales' => ['en'],
            'nvl-translatable.fallback_locales' => ['en'],
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
