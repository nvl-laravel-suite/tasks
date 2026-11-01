<?php

declare(strict_types=1);

use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Tasks\Contracts\TaskAttachments;
use Nvl\Tasks\Integrations\MediaTaskAttachments;
use Nvl\Tasks\Tests\StandaloneTasksTestCase;

uses(StandaloneTasksTestCase::class);

it('loads the explicitly enabled native Media adapter', function (): void {
    app()->register(MediaServiceProvider::class);
    config(['nvl-tasks.media.enabled' => true]);

    expect(app(TaskAttachments::class))->toBeInstanceOf(MediaTaskAttachments::class);
});
