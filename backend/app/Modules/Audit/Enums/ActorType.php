<?php

declare(strict_types=1);

namespace App\Modules\Audit\Enums;

enum ActorType: string
{
    case User = 'user';
    case PlatformUser = 'platform_user';
    case Client = 'client';
    case System = 'system';
}
