<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Http\Middleware;

use App\Modules\Integrations\Exceptions\IdempotencyKeyReused;
use App\Modules\Integrations\Exceptions\IdempotentRequestInFlight;
use App\Modules\Integrations\Models\ApiClient;
use App\Modules\Integrations\Models\IdempotencyKey;
use App\Support\Time\Clock;
use Closure;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * `Idempotency-Key` for API clients (docs/07-api/conventions.md §Idempotency).
 *
 * The first successful (2xx) response to a key is stored for 24 hours per workspace and client,
 * keyed by the SHA-256 of the key, together with a hash of the request. A repeat with the same
 * key and the same request gets the stored response again (`Idempotent-Replayed: true`) and
 * nothing is created twice; the same key with a different request is 422
 * `idempotency_key_reused`; a repeat while the first is still running is 409 `conflict`.
 * Failed responses are not stored, so a corrected retry may reuse the key. SPA users do not
 * send the header, and it is ignored for them.
 */
final class EnsureIdempotency
{
    public const ALIAS = 'idempotent';

    public const HEADER = 'Idempotency-Key';

    private const TTL_HOURS = 24;

    public function __construct(private readonly Clock $clock) {}

    public function handle(Request $request, Closure $next): Response
    {
        $client = $request->user();
        $key = $request->header(self::HEADER);

        if (! $client instanceof ApiClient || ! is_string($key) || $key === '') {
            return $next($request);
        }

        if (strlen($key) > 255 || preg_match('/^[\x21-\x7E]+$/', $key) !== 1) {
            throw ValidationException::withMessages([
                self::HEADER => ['The Idempotency-Key must be 1 to 255 visible ASCII characters.'],
            ]);
        }

        $keyHash = hash('sha256', $key);
        $requestHash = $this->requestHash($request);
        $now = $this->clock->now();

        $stored = $this->find($client, $keyHash, $now);

        if ($stored !== null) {
            return $this->replay($stored, $requestHash);
        }

        $lock = "idempotency:{$client->tenant_id}:{$client->id}:{$keyHash}";

        if (! $this->acquire($lock)) {
            throw IdempotentRequestInFlight::make();
        }

        try {
            // A request that finished between the lookup and the lock.
            $stored = $this->find($client, $keyHash, $now);

            if ($stored !== null) {
                return $this->replay($stored, $requestHash);
            }

            $response = $next($request);

            if ($response->isSuccessful() && $response instanceof JsonResponse) {
                $this->store($client, $request, $keyHash, $requestHash, $response);
            }

            return $response;
        } finally {
            $this->release($lock);
        }
    }

    private function find(ApiClient $client, string $keyHash, DateTimeInterface $now): ?IdempotencyKey
    {
        return IdempotencyKey::query()
            ->where('client_id', $client->id)
            ->where('key_hash', $keyHash)
            ->where('expires_at', '>', $now)
            ->first();
    }

    private function replay(IdempotencyKey $stored, string $requestHash): JsonResponse
    {
        if (! hash_equals($stored->request_hash, $requestHash)) {
            throw IdempotencyKeyReused::make();
        }

        return new JsonResponse($stored->response_body, $stored->response_status, ['Idempotent-Replayed' => 'true']);
    }

    private function store(ApiClient $client, Request $request, string $keyHash, string $requestHash, JsonResponse $response): void
    {
        $now = $this->clock->now();

        // Expired rows of the workspace go first; one of them may hold this very key.
        IdempotencyKey::query()->where('expires_at', '<=', $now)->delete();

        $body = $response->getData(true);

        IdempotencyKey::query()->create([
            'client_id' => $client->id,
            'key_hash' => $keyHash,
            'request_hash' => $requestHash,
            'route' => (string) ($request->route()?->getName() ?? $request->path()),
            'response_status' => $response->getStatusCode(),
            'response_body' => is_array($body) ? $body : [],
            'created_at' => $now,
            'expires_at' => $now->addHours(self::TTL_HOURS),
        ]);
    }

    private function requestHash(Request $request): string
    {
        $payload = $request->json()->all();
        self::sortRecursive($payload);

        return hash('sha256', $request->method().' '.$request->path().' '.json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<mixed>  $value
     */
    private static function sortRecursive(array &$value): void
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as &$item) {
            if (is_array($item)) {
                self::sortRecursive($item);
            }
        }
    }

    private function acquire(string $lock): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return true;
        }

        return (bool) DB::selectOne('SELECT pg_try_advisory_lock(hashtextextended(?, 0)) AS locked', [$lock])?->locked;
    }

    private function release(string $lock): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::selectOne('SELECT pg_advisory_unlock(hashtextextended(?, 0)) AS unlocked', [$lock]);
        }
    }
}
