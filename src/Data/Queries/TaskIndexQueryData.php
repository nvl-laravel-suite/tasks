<?php

declare(strict_types=1);

namespace Nvl\Tasks\Data\Queries;

use Illuminate\Validation\Rule;
use Nvl\Data\Traits\DataTransform;
use Nvl\Tasks\Enums\TaskPriority;
use Nvl\Tasks\Enums\TaskStatus;
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
        public readonly ?TaskStatus $status = null,
        public readonly ?TaskPriority $priority = null,
        public readonly ?string $assigneeId = null,
        public readonly ?int $perPage = null,
    ) {}

    /** Return transport rules for task listing.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(TaskStatus::class)],
            'priority' => ['sometimes', Rule::enum(TaskPriority::class)],
            'assigneeId' => ['sometimes', 'string', 'max:191'],
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:'.TasksConfiguration::limit('maximum_page_size', 100)],
        ];
    }
}
