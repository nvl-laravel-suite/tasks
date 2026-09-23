<?php

declare(strict_types=1);

namespace Nvl\Tasks\Data;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/** Transport-neutral identity for task authorization and audit fields. */
#[Hidden]
final class TaskActorData extends Data
{
    /** Create an explicit principal identity or trusted system context. */
    public function __construct(
        public readonly ?string $type,
        public readonly int|string|null $id,
        public readonly bool $system = false,
    ) {
        if ($system) {
            if ($type !== null || $id !== null) {
                throw new InvalidArgumentException('System task actors cannot impersonate a principal.');
            }

            return;
        }

        if ($type === null || trim($type) === '' || $id === null || (string) $id === '') {
            throw new InvalidArgumentException('Task actors require a concrete principal identity.');
        }
    }

    /** Build an identity from any persisted Eloquent authenticatable. */
    public static function fromAuthenticatable(Authenticatable $actor): self
    {
        $identifier = $actor->getAuthIdentifier();

        if (! $actor instanceof Model || ! $actor->exists
            || (! is_int($identifier) && ! is_string($identifier))) {
            throw new InvalidArgumentException('Task actors must be persisted Eloquent authenticatables.');
        }

        return new self($actor->getMorphClass(), $identifier);
    }

    /** Declare an application-trusted system operation. */
    public static function system(): self
    {
        return new self(null, null, true);
    }
}
