<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Nvl\Activity\Models\ActivityLog;
use Nvl\Support\Enums\SystemActor;
use Nvl\Tasks\Models\TaskActivityOutbox;
use Nvl\Tasks\Services\TaskLifecycle;
use Nvl\Tasks\Services\TaskReader;
use Nvl\Tasks\Services\TasksActivityDelivery;

/** Execute host-calendar assertions without leaking its timezone or frozen clock. */
function inTaskTimezone(string $timezone, Closure $test): void
{
    $previous = date_default_timezone_get();
    $configured = config('app.timezone');
    date_default_timezone_set($timezone);
    config(['app.timezone' => $timezone]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-25 12:00:00', $timezone));
    try {
        $test();
    } finally {
        CarbonImmutable::setTestNow();
        date_default_timezone_set($previous);
        config(['app.timezone' => $configured]);
    }
}

it('compares UTC deadlines at exact overdue and host-calendar due-soon boundaries', function (string $timezone): void {
    inTaskTimezone($timezone, function (): void {
        $now = CarbonImmutable::now();
        $end = $now->addDays(7);
        $actor = SystemActor::Background;
        foreach ([$now->subSecond(), $now, $now->addHour(), $end->subSecond(), $end, $end->addSecond()] as $index => $due) {
            app(TaskLifecycle::class)->create(['title' => 'Deadline '.$index, 'due_at' => $due], $actor);
        }
        $reader = app(TaskReader::class);
        expect($reader->paginate(['overdue' => true], $actor)->pluck('title')->all())->toBe(['Deadline 0'])
            ->and($reader->paginate(['overdue' => false], $actor)->total())->toBe(5);
        $summary = $reader->dashboard($actor, 7);
        expect($summary['overdue'])->toBe(1)->and($summary['due_soon'])->toBe(4);
    });
})->with(['Europe/Sofia', 'America/New_York']);

it('converts inclusive local calendar windows to UTC across daylight-saving boundaries', function (string $timezone, string $day): void {
    inTaskTimezone($timezone, function () use ($timezone, $day): void {
        $start = CarbonImmutable::parse($day, $timezone)->startOfDay();
        $next = $start->addDay()->startOfDay();
        $actor = SystemActor::Background;
        foreach ([$start->subSecond(), $start, $next->subSecond(), $next] as $index => $instant) {
            app(TaskLifecycle::class)->create(['title' => 'Calendar '.$index, 'target_at' => $instant, 'due_at' => $instant], $actor);
        }
        foreach (['target', 'due'] as $field) {
            $page = app(TaskReader::class)->paginate([$field.'_from' => $day, $field.'_to' => $day], $actor);
            expect($page->pluck('title')->all())->toEqualCanonicalizing(['Calendar 1', 'Calendar 2'])
                ->and($page->total())->toBe(2);
        }
    });
})->with([['Europe/Sofia', '2026-10-25'], ['America/New_York', '2026-11-01']]);

it('selects UTC-due outboxes and excludes active leases before the drain limit', function (string $timezone): void {
    inTaskTimezone($timezone, function (): void {
        $now = CarbonImmutable::now();
        $events = [];
        foreach ([
            'active' => [$now->subMinutes(3), $now->addMinute()],
            'expired' => [$now->subMinutes(2), $now->subSecond()],
            'due' => [$now->subMinute(), null],
            'equal-lease' => [$now->subSeconds(20), $now],
            'now' => [$now, null],
            'future' => [$now->addSecond(), null],
        ] as $name => [$available, $lease]) {
            $task = app(TaskLifecycle::class)->create(['title' => $name], SystemActor::Background);
            $event = TaskActivityOutbox::query()->where('task_id', $task->id)->sole();
            $event->forceFill(['delivered_at' => null, 'available_at' => $available, 'leased_until' => $lease, 'lease_token' => $lease === null ? null : (string) Str::uuid()])->save();
            $events[$name] = $event;
        }
        $delivery = app(TasksActivityDelivery::class);
        expect($delivery->drain(1))->toBe(1)->and($events['expired']->fresh()->delivered_at)->not->toBeNull();
        expect($delivery->drain(100))->toBe(3);
        foreach (['expired', 'due', 'equal-lease', 'now'] as $name) {
            expect($events[$name]->fresh()->delivered_at)->not->toBeNull()->and($events[$name]->fresh()->attempts)->toBe(1);
        }
        foreach (['active', 'future'] as $name) {
            expect($events[$name]->fresh()->delivered_at)->toBeNull()->and($events[$name]->fresh()->attempts)->toBe(0)
                ->and($delivery->deliver($events[$name]->id))->toBeFalse();
        }
    });
})->with(['Europe/Sofia', 'America/New_York']);

it('releases failed delivery with a UTC retry deadline and resumes exactly when due', function (string $timezone): void {
    inTaskTimezone($timezone, function (): void {
        $now = CarbonImmutable::now();
        $task = app(TaskLifecycle::class)->create(['title' => 'Retry'], SystemActor::Background);
        $event = TaskActivityOutbox::query()->where('task_id', $task->id)->sole();
        $event->forceFill(['delivered_at' => null])->save();
        $activity = ActivityLog::query()->findOrFail($event->id);
        $original = $activity->getAttribute('event');
        $activity->getConnection()->table($activity->getTable())->where('id', $activity->id)->update(['event' => 'conflict']);
        $delivery = app(TasksActivityDelivery::class);
        expect($delivery->deliver($event->id))->toBeFalse();
        $event->refresh();
        expect($event->available_at->getTimestamp())->toBe($now->addSeconds(5)->getTimestamp())
            ->and($event->getRawOriginal('available_at'))->toBe($now->addSeconds(5)->utc()->format('Y-m-d H:i:s'))
            ->and($event->leased_until)->toBeNull()->and($event->lease_token)->toBeNull()->and($event->last_error)->not->toBeNull();
        $activity->getConnection()->table($activity->getTable())->where('id', $activity->id)->update(['event' => $original]);
        CarbonImmutable::setTestNow($now->addSeconds(4));
        expect($delivery->drain(10))->toBe(0);
        CarbonImmutable::setTestNow($now->addSeconds(5));
        expect($delivery->drain(10))->toBe(1)->and($event->fresh()->attempts)->toBe(2);
    });
})->with(['Europe/Sofia', 'America/New_York']);
