<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Nvl\Ownership\Tests\OwnershipTestCase;
use Nvl\Support\Enums\SystemActor;
use Nvl\Tasks\Contracts\TaskAuthorization;
use Nvl\Tasks\Enums\TaskAbility;
use Nvl\Tasks\Models\Task;
use Nvl\Tasks\Tests\HttpTestCase;
use Nvl\Tasks\Tests\TenancyTestCase;
use Nvl\Tasks\Tests\TestCase;

pest()->extend(TestCase::class)->in(__DIR__.'/Feature');
pest()->extend(HttpTestCase::class)->in(__DIR__.'/Http');
if (class_exists(OwnershipTestCase::class)) {
    pest()->extend(TenancyTestCase::class)->in(__DIR__.'/Tenancy');
}

/** Bind an explicit host policy for behavior fixtures. */
function nativeTaskPolicy(): void
{
    app()->bind(TaskAuthorization::class, static fn () => new class implements TaskAuthorization
    {
        public function authorize(TaskAbility $ability, Authenticatable|SystemActor $actor, ?Task $task = null, ?Model $subject = null): void {}

        public function scopeTasks(Builder $query, Authenticatable|SystemActor $actor): void {}
    });
}

function nativeTaskUser(string $name = 'Native'): User
{
    $user = new User;
    $user->forceFill(['name' => $name, 'email' => strtolower($name).'@native.test', 'password' => 'unused'])->save();

    return $user;
}
