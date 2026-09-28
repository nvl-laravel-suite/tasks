<?php

declare(strict_types=1);

namespace Nvl\Tasks\Data\Mutations;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Carries a task tag mutation and its exact task revision. */
#[MapInputName(CamelCaseMapper::class)]
#[MapOutputName(CamelCaseMapper::class)]
#[TypeScript]
final class TaskTagMutationData extends Data
{
    /** Construct one tag mutation. */
    public function __construct(
        public readonly string $tag,
        public readonly int $expectedRevision,
    ) {}

    /** Return transport validation rules for a normalized task tag.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'tag' => ['required', 'string', 'max:64'],
            'expectedRevision' => ['required', 'integer', 'min:1'],
        ];
    }

    /** Normalize whitespace and case before persistence or lookup. */
    public function normalizedTag(): string
    {
        $normalized = Str::lower(Str::squish($this->tag));

        Validator::make([
            'tag' => $normalized,
            'expectedRevision' => $this->expectedRevision,
        ], self::rules())->validate();

        return $normalized;
    }
}
