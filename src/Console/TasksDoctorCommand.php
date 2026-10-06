<?php

declare(strict_types=1);

namespace Nvl\Tasks\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Nvl\Tasks\Services\TasksDoctor;

/**
 * Renders the package-owned read-only installation diagnostics.
 */
final class TasksDoctorCommand extends Command
{
    protected $signature = 'nvl:tasks:doctor {--strict} {--format=text}';

    /** @var string */
    protected $description = 'Inspect the NVL Tasks installation';

    /** Report readiness of storage and package-owned integration boundaries. */
    public function handle(TasksDoctor $doctor): int
    {

        $format = $this->option('format');

        if (! is_string($format) || ! in_array($format, ['text', 'json'], true)) {
            throw new InvalidArgumentException('The Tasks Doctor format must be text or json.');
        }

        $result = $doctor->inspect();
        $checks = $result['checks'];
        $healthy = $result['healthy'];

        if ($format === 'json') {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            foreach ($checks as $name => $passed) {
                $this->line(($passed ? 'PASS ' : 'FAIL ').$name);
            }
        }

        return $healthy || ! $this->option('strict')
            ? self::SUCCESS
            : self::FAILURE;
    }
}
