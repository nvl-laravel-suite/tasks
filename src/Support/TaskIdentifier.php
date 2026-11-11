<?php

declare(strict_types=1);

namespace Nvl\Tasks\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * Resolves native UUID keys before they reach a typed database column.
 *
 * @internal
 */
final class TaskIdentifier
{
    /**
     * Return the record's UUID key; a malformed identifier names no record, on every driver.
     *
     * @param  class-string<Model>  $model
     */
    public static function of(Model|string $record, string $model): string
    {
        $key = $record instanceof Model ? $record->getKey() : $record;

        if (! is_string($key) || ! Str::isUuid($key)) {
            throw (new ModelNotFoundException)->setModel($model);
        }

        return $key;
    }

    private function __construct() {}
}
