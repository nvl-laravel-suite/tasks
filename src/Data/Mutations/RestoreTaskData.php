<?php

declare(strict_types=1);

namespace Nvl\Tasks\Data\Mutations;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Exact revision required to restore a deleted task. */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class RestoreTaskData extends Data
{
    use DataTransform;

    /** Create one restoration payload. */
    public function __construct(public readonly int $expectedRevision) {}

    /** Return restoration validation rules.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return ['expectedRevision' => ['required', 'integer', 'min:1']];
    }
}
