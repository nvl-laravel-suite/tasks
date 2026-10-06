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
 * Complete checklist order guarded by an exact task revision.
 *
 * @api
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class ReorderTaskChecklistItemsData extends Data
{
    use DataTransform;

    /** Construct one complete checklist order.
     *
     * @param  list<string>  $itemIds
     */
    public function __construct(
        public readonly array $itemIds,
        public readonly int $expectedRevision,
    ) {}

    /** Return checklist order validation rules.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'itemIds' => ['required', 'array', 'min:1'],
            'itemIds.*' => ['required', 'uuid', 'distinct'],
            'expectedRevision' => ['required', 'integer', 'min:1'],
        ];
    }
}
