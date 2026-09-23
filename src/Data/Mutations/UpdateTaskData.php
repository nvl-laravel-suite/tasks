<?php

declare(strict_types=1);

namespace Nvl\Tasks\Data\Mutations;

use Illuminate\Validation\Rule;
use Nvl\Data\Traits\DataTransform;
use Nvl\Tasks\Enums\TaskPriority;
use Nvl\Tasks\Enums\TaskStatus;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Complete task replacement guarded by an exact revision. */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class UpdateTaskData extends Data
{
    use DataTransform;

    /** Construct one task replacement payload.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $title,
        public readonly TaskPriority $priority,
        public readonly TaskStatus $status,
        public readonly int $expectedRevision,
        public readonly ?string $description = null,
        public readonly ?string $dueAt = null,
        #[LiteralTypeScriptType('Record<string, unknown>')]
        public readonly array $metadata = [],
    ) {}

    /** Return transport validation rules for task replacement.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'priority' => ['required', Rule::enum(TaskPriority::class)],
            'status' => ['required', Rule::enum(TaskStatus::class)],
            'expectedRevision' => ['required', 'integer', 'min:1'],
            'dueAt' => ['nullable', 'date'],
            'metadata' => ['array', 'max:64'],
        ];
    }
}
