<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use stdClass;

/**
 * The Pusher-protocol signature for one private channel, unwrapped because pusher-js reads `auth`
 * at the top level. Wraps no model.
 *
 * @property-read stdClass $resource
 */
final class ChannelAuthorizationResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    public function __construct(private readonly string $signature)
    {
        parent::__construct(new stdClass);
    }

    /**
     * @return array{auth: string}
     */
    public function toArray(Request $request): array
    {
        return [
            // `<app key>:<HMAC-SHA256 of "socket_id:channel_name">`.
            'auth' => $this->signature,
        ];
    }
}
