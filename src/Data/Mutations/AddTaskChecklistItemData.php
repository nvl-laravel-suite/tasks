<?php

declare(strict_types=1);

namespace Nvl\Tasks\Data\Mutations;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Checklist item addition guarded by an exact task revision.
 *
 * @api
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class AddTaskChecklistItemData extends Data
{
    use DataTransform;

    /** Construct one checklist item addition payload. */
    public function __construct(
        public readonly string $title,
        public readonly int $expectedRevision,
    ) {}

    /** Return checklist addition validation rules.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'expectedRevision' => ['required', 'integer', 'min:1'],
        ];
    }
}
