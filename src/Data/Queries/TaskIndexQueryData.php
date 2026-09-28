<?php

declare(strict_types=1);

namespace Nvl\Tasks\Data\Queries;

use Illuminate\Validation\Rule;
use Nvl\Data\Traits\DataTransform;
use Nvl\Tasks\Support\TaskEnumConfiguration;
use Nvl\Tasks\Support\TasksConfiguration;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Validated task table filters and page size. */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class TaskIndexQueryData extends Data
{
    use DataTransform;

    /** Create one bounded task query. */
    public function __construct(
        public readonly ?string $status = null,
        public readonly ?string $priority = null,
        public readonly ?string $assigneeId = null,
        public readonly ?int $perPage = null,
        public readonly ?string $type = null,
        public readonly ?string $category = null,
        public readonly ?string $importance = null,
        public readonly ?string $tag = null,
        public readonly ?string $targetFrom = null,
        public readonly ?string $targetTo = null,
        public readonly ?string $dueFrom = null,
        public readonly ?string $dueTo = null,
        public readonly ?bool $overdue = null,
    ) {}

    /** Return transport rules for task listing.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(TaskEnumConfiguration::enumClass('status'))],
            'priority' => ['nullable', Rule::enum(TaskEnumConfiguration::enumClass('priority'))],
            'type' => ['nullable', Rule::enum(TaskEnumConfiguration::enumClass('type'))],
            'category' => ['nullable', Rule::enum(TaskEnumConfiguration::enumClass('category'))],
            'importance' => ['nullable', Rule::enum(TaskEnumConfiguration::enumClass('importance'))],
            'assigneeId' => ['nullable', 'string', 'max:191'],
            'tag' => ['nullable', 'string', 'max:64'],
            'targetFrom' => ['nullable', 'date_format:Y-m-d'],
            'targetTo' => ['nullable', 'date_format:Y-m-d'],
            'dueFrom' => ['nullable', 'date_format:Y-m-d'],
            'dueTo' => ['nullable', 'date_format:Y-m-d'],
            'overdue' => ['nullable', 'boolean'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:'.TasksConfiguration::limit('maximum_page_size', 100)],
        ];
    }
}
