<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\Container;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Adds actor and version fields to every record (docs/11-operations/logs.md §Format).
 *
 * Request, tenant and job ids come from Laravel's Context, which the framework already
 * merges into `extra` and carries into queued jobs.
 */
final class ContextProcessor implements ProcessorInterface
{
    public function __construct(private readonly Container $container) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = ['app_version' => (string) config('helpdesk.version')];

        $actor = $this->actor();
        if ($actor !== null) {
            $extra['user_id'] = $actor['id'];
            $extra['actor_type'] = $actor['type'];
        }

        return $record->with(extra: [...$record->extra, ...$extra]);
    }

    /**
     * Reads the user only when a guard has already resolved one, so logging never triggers
     * authentication (which could itself log and recurse).
     *
     * @return array{id: mixed, type: string}|null
     */
    private function actor(): ?array
    {
        if (! $this->container->resolved('auth')) {
            return null;
        }

        $auth = $this->container->make(AuthFactory::class);
        $guard = $auth->guard();

        if (! $guard->hasUser()) {
            return null;
        }

        $user = $guard->user();

        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->getAuthIdentifier(),
            'type' => 'user',
        ];
    }
}
