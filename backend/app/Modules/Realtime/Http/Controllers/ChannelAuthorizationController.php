<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Http\Controllers;

use App\Modules\Realtime\Http\Requests\AuthorizeChannelRequest;
use App\Modules\Realtime\Http\Resources\ChannelAuthorizationResource;
use App\Modules\Realtime\Support\Channels;
use App\Modules\Realtime\Support\RealtimeSwitch;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastManager;

/**
 * Pusher-protocol channel authorisation for Echo (docs/03-architecture/realtime.md §Authorisation).
 * Runs in the `tenant` route group, so the workspace comes from the session and API clients are
 * refused; {@see Channels} compares the channel's tenant with it and checks the permission.
 */
final readonly class ChannelAuthorizationController
{
    public function __construct(private BroadcastManager $broadcasting, private RealtimeSwitch $switch) {}

    /**
     * Authorise a live-update channel.
     *
     * Called by the SPA's WebSocket client (Echo) before it joins a private channel. The channel's
     * workspace must be the session's; the ticket channels need `tickets.view` and a ticket of the
     * workspace, the internal-note channel also `comments.internal`, a user channel is its owner's
     * only. 404 when this installation runs without Reverb (the SPA then polls).
     */
    public function __invoke(AuthorizeChannelRequest $request): ChannelAuthorizationResource
    {
        // The log and null broadcasters answer every channel with 200; with realtime off there is nothing to join.
        abort_unless($this->switch->serverEnabled(), 404);

        $broadcaster = $this->broadcasting->connection();
        abort_unless($broadcaster instanceof Broadcaster, 404);

        Channels::registerOn($broadcaster);

        /** @var array{auth: string} $signature */
        $signature = $broadcaster->auth($request);

        return new ChannelAuthorizationResource($signature['auth']);
    }
}
