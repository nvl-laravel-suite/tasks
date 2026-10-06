<?php

declare(strict_types=1);

namespace Nvl\Tasks\Data;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Spatie\LaravelData\Attributes\Hidden as DataHidden;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Transport-neutral identity for task authorization and audit fields.
 *
 * @api
 */
#[Hidden]
final class TaskActorData extends Data
{
    /** Create an explicit principal identity or trusted system context. */
    public function __construct(
        public readonly ?string $type,
        public readonly int|string|null $id,
        public readonly bool $system = false,
        #[DataHidden]
        private readonly ?Model $principal = null,
    ) {
        if ($system) {
            if ($type !== null || $id !== null || $principal !== null) {
                throw new InvalidArgumentException('System task actors cannot impersonate a principal.');
            }

            return;
        }

        if ($type === null || trim($type) === '' || $id === null || (string) $id === '') {
            throw new InvalidArgumentException('Task actors require a concrete principal identity.');
        }

        if ($principal !== null) {
            $principalId = $principal->getKey();

            if (! $principal->exists || $principal->getMorphClass() !== $type
                || (! is_int($principalId) && ! is_string($principalId))
                || (string) $principalId !== (string) $id) {
                throw new InvalidArgumentException('The task actor principal must match its persisted identity.');
            }
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

        return new self($actor->getMorphClass(), $identifier, principal: $actor);
    }

    /** Return the validated principal model when it was supplied by authentication. */
    public function principal(): ?Model
    {
        return $this->principal;
    }

    /** Declare an application-trusted system operation. */
    public static function system(): self
    {
        return new self(null, null, true);
    }
}
