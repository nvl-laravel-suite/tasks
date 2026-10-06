<?php

declare(strict_types=1);

namespace Nvl\Tasks\Data\Mutations;

use BackedEnum;
use Illuminate\Validation\Rule;
use Nvl\Data\Traits\DataTransform;
use Nvl\Tasks\Support\TaskEnumConfiguration;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Validated input for creating one task without accepting ownership fields.
 *
 * @api
 */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class CreateTaskData extends Data
{
    use DataTransform;

    /** Construct one task creation payload.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $title,
        public readonly ?string $description = null,
        #[LiteralTypeScriptType('string | null')]
        public readonly BackedEnum|string|null $priority = null,
        #[LiteralTypeScriptType('string | null')]
        public readonly BackedEnum|string|null $status = null,
        public readonly ?string $dueAt = null,
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $metadata = [],
        #[LiteralTypeScriptType('string | null')]
        public readonly BackedEnum|string|null $type = null,
        #[LiteralTypeScriptType('string | null')]
        public readonly BackedEnum|string|null $category = null,
        #[LiteralTypeScriptType('string | null')]
        public readonly BackedEnum|string|null $importance = null,
        public readonly ?string $targetAt = null,
        public readonly ?int $estimatedSeconds = null,
    ) {}

    /** Return transport validation rules for task creation.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'priority' => ['nullable', Rule::enum(TaskEnumConfiguration::enumClass('priority'))],
            'status' => ['nullable', Rule::enum(TaskEnumConfiguration::enumClass('status'))],
            'type' => ['nullable', Rule::enum(TaskEnumConfiguration::enumClass('type'))],
            'category' => ['nullable', Rule::enum(TaskEnumConfiguration::enumClass('category'))],
            'importance' => ['nullable', Rule::enum(TaskEnumConfiguration::enumClass('importance'))],
            'dueAt' => ['nullable', 'date', 'after_or_equal:targetAt'],
            'targetAt' => ['nullable', 'date'],
            'estimatedSeconds' => ['nullable', 'integer', 'min:1', 'max:31536000'],
            'metadata' => ['array', 'max:64'],
        ];
    }
}
