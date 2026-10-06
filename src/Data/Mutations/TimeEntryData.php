<?php

declare(strict_types=1);

namespace Nvl\Tasks\Data\Mutations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Describes a manual time interval while leaving its duration to the server.
 *
 * @api
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class TimeEntryData extends Data
{
    /** Construct a manual time entry replacement. */
    public function __construct(
        public readonly string $startedAt,
        public readonly string $endedAt,
        public readonly int $expectedRevision,
        public readonly ?string $description = null,
    ) {}

    /** Return transport validation rules for the manual interval.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'startedAt' => ['required', 'date'],
            'endedAt' => ['required', 'date'],
            'expectedRevision' => ['required', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** Parse and validate a positive interval of no more than seven days.
     *
     * @return array{started_at: CarbonImmutable, stopped_at: CarbonImmutable, duration_seconds: int, description: ?string}
     */
    public function interval(): array
    {
        Validator::make([
            'startedAt' => $this->startedAt,
            'endedAt' => $this->endedAt,
            'expectedRevision' => $this->expectedRevision,
            'description' => $this->description,
        ], self::rules())->validate();

        $started = CarbonImmutable::parse($this->startedAt);
        $ended = CarbonImmutable::parse($this->endedAt);
        $seconds = $ended->getTimestamp() - $started->getTimestamp();

        if ($seconds < 1 || $seconds > 604_800) {
            throw ValidationException::withMessages(['endedAt' => 'The time entry must last between one second and seven days.']);
        }

        return [
            'started_at' => $started,
            'stopped_at' => $ended,
            'duration_seconds' => $seconds,
            'description' => $this->description,
        ];
    }
}
