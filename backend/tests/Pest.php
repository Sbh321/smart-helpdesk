<?php

declare(strict_types=1);

use Tests\TestCase;

/*
 * Feature and isolation tests boot the application; unit and architecture tests do not.
 * Tenancy helpers live in tests/Support/TenancyHelpers.php.
 */
require_once __DIR__.'/Support/TenancyHelpers.php';
require_once __DIR__.'/Support/PlatformHelpers.php';
pest()->extend(TestCase::class)->in('Feature', 'Isolation', 'Permissions');
