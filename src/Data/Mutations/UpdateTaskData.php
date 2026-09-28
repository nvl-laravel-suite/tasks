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
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Revision-guarded task replacement with explicit optional-field clearing. */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class UpdateTaskData extends Data
{
    use DataTransform;

    /** Construct one task replacement payload.
     *
     * @param  array<string, mixed>|Optional  $metadata
     */
    public function __construct(
        public readonly string $title,
        #[LiteralTypeScriptType('string')]
        public readonly BackedEnum|string $priority,
        #[LiteralTypeScriptType('string')]
        public readonly BackedEnum|string $status,
        public readonly int $expectedRevision,
        #[TypeScriptOptional]
        public readonly string|Optional|null $description = new Optional,
        #[TypeScriptOptional]
        public readonly string|Optional|null $dueAt = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array|Optional $metadata = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly BackedEnum|string|Optional|null $type = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly BackedEnum|string|Optional|null $category = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly BackedEnum|string|Optional|null $importance = new Optional,
        #[TypeScriptOptional]
        public readonly string|Optional|null $targetAt = new Optional,
        #[TypeScriptOptional]
        public readonly int|Optional|null $estimatedSeconds = new Optional,
    ) {}

    /** Return transport validation rules for task replacement.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'priority' => ['required', Rule::enum(TaskEnumConfiguration::enumClass('priority'))],
            'status' => ['required', Rule::enum(TaskEnumConfiguration::enumClass('status'))],
            'type' => ['sometimes', 'nullable', Rule::enum(TaskEnumConfiguration::enumClass('type'))],
            'category' => ['sometimes', 'nullable', Rule::enum(TaskEnumConfiguration::enumClass('category'))],
            'importance' => ['sometimes', 'nullable', Rule::enum(TaskEnumConfiguration::enumClass('importance'))],
            'expectedRevision' => ['required', 'integer', 'min:1'],
            'dueAt' => ['sometimes', 'nullable', 'date', 'after_or_equal:targetAt'],
            'targetAt' => ['sometimes', 'nullable', 'date'],
            'estimatedSeconds' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:31536000'],
            'metadata' => ['sometimes', 'array', 'max:64'],
        ];
    }
}
