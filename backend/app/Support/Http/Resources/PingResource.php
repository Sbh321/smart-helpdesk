<?php

declare(strict_types=1);

namespace App\Support\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use stdClass;

/**
 * Liveness answer of `GET /v1/ping`. Wraps no model; the class exists so the OpenAPI document types the response.
 *
 * @property-read stdClass $resource
 */
final class PingResource extends JsonResource
{
    public function __construct()
    {
        parent::__construct(new stdClass);
    }

    /**
     * @return array{status: 'ok'}
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => 'ok',
        ];
    }
}
