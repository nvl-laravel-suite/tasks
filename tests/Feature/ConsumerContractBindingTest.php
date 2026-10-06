<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Nvl\Tasks\Actions\AddTaskChecklistItemAction;
use Nvl\Tasks\Actions\AddTaskDependencyAction;
use Nvl\Tasks\Actions\AddTaskTagAction;
use Nvl\Tasks\Actions\AddTaskTimeEntryAction;
use Nvl\Tasks\Actions\AssignTaskAction;
use Nvl\Tasks\Actions\CreateTaskAction;
use Nvl\Tasks\Actions\DeleteTaskAction;
use Nvl\Tasks\Actions\DeleteTaskTimeEntryAction;
use Nvl\Tasks\Actions\GetTaskAction;
use Nvl\Tasks\Actions\GetTaskDashboardAction;
use Nvl\Tasks\Actions\GetTaskDetailAction;
use Nvl\Tasks\Actions\LinkTaskParentAction;
use Nvl\Tasks\Actions\ListTaskChecklistItemsAction;
use Nvl\Tasks\Actions\ListTasksAction;
use Nvl\Tasks\Actions\ListTaskTimeEntriesAction;
use Nvl\Tasks\Actions\RemoveTaskChecklistItemAction;
use Nvl\Tasks\Actions\RemoveTaskDependencyAction;
use Nvl\Tasks\Actions\RemoveTaskTagAction;
use Nvl\Tasks\Actions\ReorderTaskChecklistItemsAction;
use Nvl\Tasks\Actions\RestoreTaskAction;
use Nvl\Tasks\Actions\StartTaskTimerAction;
use Nvl\Tasks\Actions\StopTaskTimerAction;
use Nvl\Tasks\Actions\ToggleTaskChecklistItemAction;
use Nvl\Tasks\Actions\UnassignTaskAction;
use Nvl\Tasks\Actions\UnlinkTaskParentAction;
use Nvl\Tasks\Actions\UpdateTaskAction;
use Nvl\Tasks\Actions\UpdateTaskChecklistItemAction;
use Nvl\Tasks\Actions\UpdateTaskTimeEntryAction;
use Nvl\Tasks\Contracts\AddTaskChecklistItemContract;
use Nvl\Tasks\Contracts\AddTaskDependencyContract;
use Nvl\Tasks\Contracts\AddTaskTagContract;
use Nvl\Tasks\Contracts\AddTaskTimeEntryContract;
use Nvl\Tasks\Contracts\AssignTaskContract;
use Nvl\Tasks\Contracts\CreateTaskContract;
use Nvl\Tasks\Contracts\DeleteTaskContract;
use Nvl\Tasks\Contracts\DeleteTaskTimeEntryContract;
use Nvl\Tasks\Contracts\GetTaskContract;
use Nvl\Tasks\Contracts\GetTaskDashboardContract;
use Nvl\Tasks\Contracts\GetTaskDetailContract;
use Nvl\Tasks\Contracts\LinkTaskParentContract;
use Nvl\Tasks\Contracts\ListTaskChecklistItemsContract;
use Nvl\Tasks\Contracts\ListTasksContract;
use Nvl\Tasks\Contracts\ListTaskTimeEntriesContract;
use Nvl\Tasks\Contracts\RemoveTaskChecklistItemContract;
use Nvl\Tasks\Contracts\RemoveTaskDependencyContract;
use Nvl\Tasks\Contracts\RemoveTaskTagContract;
use Nvl\Tasks\Contracts\ReorderTaskChecklistItemsContract;
use Nvl\Tasks\Contracts\RestoreTaskContract;
use Nvl\Tasks\Contracts\StartTaskTimerContract;
use Nvl\Tasks\Contracts\StopTaskTimerContract;
use Nvl\Tasks\Contracts\ToggleTaskChecklistItemContract;
use Nvl\Tasks\Contracts\UnassignTaskContract;
use Nvl\Tasks\Contracts\UnlinkTaskParentContract;
use Nvl\Tasks\Contracts\UpdateTaskChecklistItemContract;
use Nvl\Tasks\Contracts\UpdateTaskContract;
use Nvl\Tasks\Contracts\UpdateTaskTimeEntryContract;
use Nvl\Tasks\Providers\TasksServiceProvider;
use Nvl\Tasks\Tests\TestCase;

if (! in_array(dirname(__DIR__).'/Pest.php', get_included_files(), true)) {
    uses(TestCase::class);
}

/** @return list<array{class-string, class-string}> */
function nvlConsumerBindingsForTasks(): array
{
    return [
        [AddTaskChecklistItemContract::class, AddTaskChecklistItemAction::class],
        [AddTaskDependencyContract::class, AddTaskDependencyAction::class],
        [AddTaskTagContract::class, AddTaskTagAction::class],
        [AddTaskTimeEntryContract::class, AddTaskTimeEntryAction::class],
        [AssignTaskContract::class, AssignTaskAction::class],
        [CreateTaskContract::class, CreateTaskAction::class],
        [DeleteTaskContract::class, DeleteTaskAction::class],
        [DeleteTaskTimeEntryContract::class, DeleteTaskTimeEntryAction::class],
        [GetTaskContract::class, GetTaskAction::class],
        [GetTaskDashboardContract::class, GetTaskDashboardAction::class],
        [GetTaskDetailContract::class, GetTaskDetailAction::class],
        [LinkTaskParentContract::class, LinkTaskParentAction::class],
        [ListTaskChecklistItemsContract::class, ListTaskChecklistItemsAction::class],
        [ListTaskTimeEntriesContract::class, ListTaskTimeEntriesAction::class],
        [ListTasksContract::class, ListTasksAction::class],
        [RemoveTaskChecklistItemContract::class, RemoveTaskChecklistItemAction::class],
        [RemoveTaskDependencyContract::class, RemoveTaskDependencyAction::class],
        [RemoveTaskTagContract::class, RemoveTaskTagAction::class],
        [ReorderTaskChecklistItemsContract::class, ReorderTaskChecklistItemsAction::class],
        [RestoreTaskContract::class, RestoreTaskAction::class],
        [StartTaskTimerContract::class, StartTaskTimerAction::class],
        [StopTaskTimerContract::class, StopTaskTimerAction::class],
        [ToggleTaskChecklistItemContract::class, ToggleTaskChecklistItemAction::class],
        [UnassignTaskContract::class, UnassignTaskAction::class],
        [UnlinkTaskParentContract::class, UnlinkTaskParentAction::class],
        [UpdateTaskChecklistItemContract::class, UpdateTaskChecklistItemAction::class],
        [UpdateTaskContract::class, UpdateTaskAction::class],
        [UpdateTaskTimeEntryContract::class, UpdateTaskTimeEntryAction::class],
    ];
}

test('published workflow contracts retain native signatures attributes and generic documentation', function (): void {
    $genericDocumentation = static function (string|false $documentation): array {
        if ($documentation === false) {
            return [];
        }
        preg_match_all('/@param\s+([^\r\n]+?)\s+(\$[A-Za-z_][A-Za-z0-9_]*)\b/', $documentation, $parameters, PREG_SET_ORDER);
        $result = [];
        foreach ($parameters as $parameter) {
            $type = preg_replace('/\s+/', '', $parameter[1]);
            if (str_contains($type, '<') || str_contains($type, '{') || str_contains($type, '[]')) {
                $result['@param'.$parameter[2]] = $type;
            }
        }
        if (preg_match('/@return\s+([^\r\n]+)/', $documentation, $return) === 1) {
            $type = '';
            $depth = 0;
            foreach (str_split($return[1]) as $character) {
                if (preg_match('/\s/', $character) === 1 && $depth === 0) {
                    break;
                }
                if (str_contains('<{([', $character)) {
                    $depth++;
                } elseif (str_contains('>})]', $character)) {
                    $depth--;
                }
                if (preg_match('/\s/', $character) !== 1) {
                    $type .= $character;
                }
            }
            if (str_contains($type, '<') || str_contains($type, '{') || str_contains($type, '[]')) {
                $result['@return'] = $type;
            }
        }

        return $result;
    };

    foreach (nvlConsumerBindingsForTasks() as [$contract, $implementation]) {
        $interface = new ReflectionClass($contract);
        $concrete = new ReflectionClass($implementation);
        expect($interface->isInterface())->toBeTrue()
            ->and($concrete->implementsInterface($contract))->toBeTrue();
        foreach ($interface->getMethods() as $method) {
            $native = $concrete->getMethod($method->getName());
            $return = (string) $method->getReturnType();

            $publishedTypes = $genericDocumentation($method->getDocComment());
            foreach ($genericDocumentation($native->getDocComment()) as $tag => $type) {
                expect($publishedTypes[$tag] ?? null)->toBe($type);
            }

            expect($native->isPublic())->toBeTrue()
                ->and($native->isStatic())->toBeFalse()
                ->and(count($method->getParameters()))->toBe(count($native->getParameters()));
            if ($return !== 'self') {
                expect((string) $native->getReturnType())->toBe($return);
            } else {
                $nativeReturn = (string) $native->getReturnType();
                expect(is_a(in_array($nativeReturn, ['self', 'static'], true) ? $native->getDeclaringClass()->getName() : $nativeReturn, $contract, true))->toBeTrue();
            }
            foreach ($method->getParameters() as $position => $parameter) {
                $actual = $native->getParameters()[$position];
                expect($actual->getName())->toBe($parameter->getName())
                    ->and((string) $actual->getType())->toBe((string) $parameter->getType())
                    ->and($actual->isVariadic())->toBe($parameter->isVariadic())
                    ->and($actual->isPassedByReference())->toBe($parameter->isPassedByReference())
                    ->and($actual->isDefaultValueAvailable())->toBe($parameter->isDefaultValueAvailable())
                    ->and(array_map(static fn (ReflectionAttribute $attribute): array => [$attribute->getName(), $attribute->getArguments()], $actual->getAttributes()))
                    ->toBe(array_map(static fn (ReflectionAttribute $attribute): array => [$attribute->getName(), $attribute->getArguments()], $parameter->getAttributes()));
                if ($parameter->isDefaultValueAvailable()) {
                    expect($actual->getDefaultValue())->toEqual($parameter->getDefaultValue());
                }
            }
        }
    }
});

test('native provider defaults resolve each workflow while preserving late host substitutes', function (): void {
    foreach (nvlConsumerBindingsForTasks() as [$contract, $implementation]) {
        expect($this->app->bound($contract))->toBeTrue()
            ->and($this->app->make($contract))->toBeInstanceOf($implementation);
        $host = Mockery::mock($contract);
        $this->app->instance($contract, $host);
        expect($this->app->make($contract))->toBe($host);
    }
});

test('provider registration preserves early interface bindings in a second native application', function (): void {
    $consumer = new Application($this->app->basePath());
    $consumer->instance('config', new Repository($this->app->make('config')->all()));
    $consumer->instance('env', 'testing');
    $consumer->register(FilesystemServiceProvider::class);
    $hosts = [];
    foreach (nvlConsumerBindingsForTasks() as [$contract]) {
        $hosts[$contract] = Mockery::mock($contract);
        $consumer->instance($contract, $hosts[$contract]);
    }
    try {
        $consumer->register(TasksServiceProvider::class);
        foreach ($hosts as $contract => $host) {
            expect($consumer->make($contract))->toBe($host);
        }
    } finally {
        Container::setInstance($this->app);
        $consumer->flush();
    }
});
