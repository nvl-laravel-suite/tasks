<?php

declare(strict_types=1);

namespace Nvl\Tasks\Data\Mutations;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Host principal identifier for a task assignment. */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class AssignTaskData extends Data
{
    use DataTransform;

    /** Create one assignment payload. */
    public function __construct(public readonly string $assigneeId) {}

    /** Return assignment validation rules.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return ['assigneeId' => ['required', 'string', 'max:191']];
    }
}
