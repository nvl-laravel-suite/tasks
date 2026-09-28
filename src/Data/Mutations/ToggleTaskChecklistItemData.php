<?php

declare(strict_types=1);

namespace Nvl\Tasks\Data\Mutations;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Explicit checklist completion state with optimistic concurrency. */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class ToggleTaskChecklistItemData extends Data
{
    use DataTransform;

    /** Construct one checklist completion payload. */
    public function __construct(
        public readonly bool $completed,
        public readonly int $expectedRevision,
    ) {}

    /** Return checklist completion validation rules.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'completed' => ['required', 'boolean'],
            'expectedRevision' => ['required', 'integer', 'min:1'],
        ];
    }
}
