<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Providers\TasksServiceProvider;
use Nvl\Tasks\Services\TaskAssignments;
use Nvl\Tasks\Services\TaskChecklist;
use Nvl\Tasks\Services\TaskLifecycle;
use Nvl\Tasks\Services\TaskReader;
use Nvl\Tasks\Services\TaskRelationships;
use Nvl\Tasks\Services\TaskTags;
use Nvl\Tasks\Services\TaskTimeTracking;

function nativeTaskServices(): array
{
    return [
        TaskLifecycle::class => ['create', 'update', 'delete', 'restore'],
        TaskAssignments::class => ['assign', 'unassign'],
        TaskChecklist::class => ['add', 'update', 'toggle', 'reorder', 'remove'],
        TaskTags::class => ['add', 'remove'],
        TaskTimeTracking::class => ['add', 'update', 'delete', 'startTimer', 'stopTimer'],
        TaskRelationships::class => ['linkParent', 'unlinkParent', 'addDependency', 'removeDependency'],
        TaskReader::class => ['get', 'paginate', 'checklist', 'timeEntries', 'dashboard'],
    ];
}

test('published workflow contracts retain native signatures attributes and generic documentation', function (): void {
    foreach (nativeTaskServices() as $class => $methods) {
        $reflection = new ReflectionClass($class);
        foreach ($methods as $method) {
            $operation = $reflection->getMethod($method);
            expect($operation->isPublic())->toBeTrue()->and($operation->getReturnType())->not->toBeNull();
            foreach ($operation->getParameters() as $parameter) {
                expect((string) $parameter->getType())->not->toContain('Data', 'Request');
                if ($parameter->getName() === 'actor') {
                    expect((string) $parameter->getType())->toContain('Authenticatable', 'SystemActor');
                }
                if ((string) $parameter->getType() === 'array') {
                    expect($operation->getDocComment())->toContain('@param', '$'.$parameter->getName());
                }
            }
        }
    }
});

test('native provider defaults resolve each workflow while preserving late host substitutes', function (): void {
    foreach (nativeTaskServices() as $class => $methods) {
        expect(app($class))->toBeInstanceOf($class);
    }
    $host = app(TaskAuthorization::class);
    expect(app(TaskLifecycle::class)->create(['title' => 'Native host policy'], nativeTaskUser())->exists)->toBeTrue();
    expect(app(TaskAuthorization::class))->toBeInstanceOf($host::class);
});

test('provider registration preserves early interface bindings in a second native application', function (): void {
    $host = app(TaskAuthorization::class);
    $consumer = new Application($this->app->basePath());
    $consumer->instance('config', new Repository($this->app->make('config')->all()));
    $consumer->instance('env', 'testing');
    $consumer->register(FilesystemServiceProvider::class);
    $consumer->instance(TaskAuthorization::class, $host);
    try {
        $consumer->register(TasksServiceProvider::class);
        expect($consumer->make(TaskAuthorization::class))->toBe($host);
    } finally {
        Container::setInstance($this->app);
        $consumer->flush();
    }
});

test('publishes all seven native service families in the consumer API catalog', function (): void {
    $catalog = json_decode(file_get_contents(__DIR__.'/../../resources/consumer-api.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach (nativeTaskServices() as $class => $methods) {
        expect($catalog['symbols'])->toHaveKey($class);
    }
});
