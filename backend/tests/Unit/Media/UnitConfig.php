<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;

/*
 * AllowedMedia reads two configuration values through config(). Unit tests do not boot the
 * application, so they get a bare container that holds nothing but the real helpdesk.php values.
 */

/** @param array<string, mixed> $overrides */
function bootMediaConfig(array $overrides = []): void
{
    /** @var array{media: array<string, mixed>} $helpdesk */
    $helpdesk = (static fn (): array => require dirname(__DIR__, 3).'/config/helpdesk.php')();
    $helpdesk['media'] = [...$helpdesk['media'], ...$overrides];

    $container = new Container;
    $container->instance('config', new Repository(['helpdesk' => $helpdesk]));
    Container::setInstance($container);
}

function resetMediaConfig(): void
{
    Container::setInstance(null);
}
