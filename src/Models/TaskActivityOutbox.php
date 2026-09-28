<?php

declare(strict_types=1);

namespace Nvl\Tasks\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;
use Nvl\Tasks\Definitions\Tables\TasksTables;
use Nvl\Tasks\Support\TasksConfiguration;

/** A durable, immutable activity payload with independent tenant ownership.
 *
 * @property string $id
 * @property string $task_id
 * @property string|null $tenant_id
 * @property array<string, mixed> $payload
 * @property int $attempts
 * @property CarbonImmutable $available_at
 * @property string|null $lease_token
 * @property CarbonImmutable|null $leased_until
 * @property CarbonImmutable|null $delivered_at
 * @property string|null $last_error
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class TaskActivityOutbox extends Model
{
    use HasUuids;

    public const string TENANT_RESOURCE = 'tasks.activity_outbox';

    /** @var list<string> */
    protected $fillable = [
        'task_id',
        'tenant_id',
        'payload',
        'available_at',
    ];

    /** @var array<string, int> */
    protected $attributes = ['attempts' => 0];

    /** Return the configured activity outbox table. */
    public function getTable(): string
    {
        return TasksConfiguration::table(TasksTables::ActivityOutbox);
    }

    /** Return the configured Tasks database connection. */
    public function getConnectionName(): ?string
    {
        return TasksConfiguration::connection() ?? parent::getConnectionName();
    }

    /** Prevent an activity envelope from changing after it is staged. */
    protected static function booted(): void
    {
        self::updating(static function (self $outbox): void {
            if ($outbox->isDirty('payload')) {
                throw new LogicException('A staged task activity payload cannot be changed.');
            }
        });
    }

    /** Return casts aligned with durable delivery state.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'available_at' => 'immutable_datetime',
            'leased_until' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
        ];
    }
}
