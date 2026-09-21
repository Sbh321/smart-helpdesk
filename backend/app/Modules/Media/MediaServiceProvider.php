<?php

declare(strict_types=1);

namespace App\Modules\Media;

use App\Modules\Media\Console\CleanupMedia;
use App\Modules\Media\Console\EnsureBucketCommand;
use App\Modules\Media\Models\MediaItem;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schedule;

final class MediaServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        // `taggables.taggable_type` stores this alias, never the class name.
        Relation::morphMap(['media_item' => MediaItem::class]);

        // Thirty upload intents a minute per user (docs/03-architecture/security.md §Rate limiting); each one reserves quota.
        RateLimiter::for('media-intent', fn (Request $request): Limit => Limit::perMinute(30)
            ->by('media-intent:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        if ($this->app->runningInConsole()) {
            $this->commands([EnsureBucketCommand::class, CleanupMedia::class]);
            Schedule::command('media:cleanup')->hourly()->onOneServer()->withoutOverlapping();
        }
    }
}
