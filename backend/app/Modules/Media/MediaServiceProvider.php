<?php

declare(strict_types=1);

namespace App\Modules\Media;

use App\Modules\Media\Console\EnsureBucketCommand;
use App\Support\Modules\ModuleServiceProvider;

final class MediaServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([EnsureBucketCommand::class]);
        }
    }
}
