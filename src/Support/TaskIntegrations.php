<?php

declare(strict_types=1);

namespace Nvl\Tasks\Support;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Nvl\Support\Integrations\OptionalIntegration;

/** Explicitly selects checked optional Tasks adapters. @internal */
final readonly class TaskIntegrations
{
    public function __construct(private Repository $config, private OptionalIntegration $integrations) {}

    /** Null and package discovery never grant integration authority. */
    public function enabled(string $key, string $provider): bool
    {
        $flag = $this->config->get('nvl-'.$key);
        if ($flag !== null && ! is_bool($flag)) {
            throw new InvalidArgumentException("nvl-{$key} must be true, false, or null.");
        }

        return $flag === true && $this->integrations->enabled($key, $provider);
    }

    /** Report actual explicit adapter readiness without enabling installed packages.
     * @return array{key: string, severity: string, passed: bool, message: string}
     */
    public function check(string $key, string $provider): array
    {
        try {
            $active = $this->enabled($key, $provider);

            return ['key' => 'nvl-'.$key, 'severity' => 'info', 'passed' => true, 'message' => $active ? 'The optional adapter is explicitly active.' : 'The optional adapter is inactive.'];
        } catch (InvalidArgumentException $exception) {
            return ['key' => 'nvl-'.$key, 'severity' => 'error', 'passed' => false, 'message' => $exception->getMessage()];
        }
    }
}
