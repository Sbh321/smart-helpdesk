<?php

declare(strict_types=1);

use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;

final class TicketAlreadyClosed extends DomainException
{
    public function code(): ErrorCode
    {
        return ErrorCode::InvalidTransition;
    }
}

beforeEach(function (): void {
    Route::middleware('api')->prefix('v1/test-errors')->group(function (): void {
        Route::post('/validation', fn () => Validator::validate(request()->all(), ['subject' => ['required', 'string']]));
        Route::post('/domain', fn () => throw new TicketAlreadyClosed('Ticket #1042 cannot move from closed to pending.', ['allowed' => ['open']]));
        Route::get('/crash', fn () => throw new RuntimeException('SQLSTATE secret connection string'));
        Route::get('/forbidden', fn () => abort(403, 'Only owners can do this.'));
        Route::get('/throttled', fn () => 'ok')->middleware('throttle:1,1');
    });
});

it('renders validation errors as problem details with field errors', function (): void {
    $response = $this->postJson('/v1/test-errors/validation', []);

    $response->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJson([
            'type' => 'https://docs.shp.localhost/errors/validation_failed',
            'title' => 'The given data was invalid',
            'status' => 422,
            'code' => 'validation_failed',
            'instance' => '/v1/test-errors/validation',
        ])
        ->assertJsonStructure(['errors' => ['subject']]);

    expect($response->json('request_id'))->toBe($response->headers->get('X-Request-Id'));
});

it('renders a domain exception with its code, detail and meta', function (): void {
    $this->postJson('/v1/test-errors/domain')
        ->assertStatus(422)
        ->assertJson([
            'code' => 'invalid_transition',
            'title' => 'Invalid status transition',
            'detail' => 'Ticket #1042 cannot move from closed to pending.',
            'meta' => ['allowed' => ['open']],
        ]);
});

it('hides the details of an unexpected error but keeps the request id', function (): void {
    config(['app.debug' => false]);

    $response = $this->getJson('/v1/test-errors/crash');

    $response->assertStatus(500)
        ->assertJson(['code' => 'internal_error', 'title' => 'Something went wrong', 'status' => 500])
        ->assertJsonMissingPath('detail')
        ->assertJsonMissingPath('debug');

    expect($response->getContent())->not->toContain('SQLSTATE')
        ->and($response->json('request_id'))->toBeString()->not->toBeEmpty();
});

it('adds the exception class for unexpected errors in debug mode only', function (): void {
    config(['app.debug' => true]);

    $this->getJson('/v1/test-errors/crash')
        ->assertStatus(500)
        ->assertJsonPath('debug.exception', RuntimeException::class);
});

it('maps unauthenticated, forbidden and not found', function (): void {
    Route::middleware(['api', 'auth:web'])->get('/v1/test-errors/private', fn () => 'secret');

    $this->getJson('/v1/test-errors/private')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
    $this->getJson('/v1/test-errors/forbidden')->assertStatus(403)->assertJson(['code' => 'forbidden', 'detail' => 'Only owners can do this.']);
    $this->getJson('/v1/does-not-exist')->assertStatus(404)->assertJsonPath('code', 'not_found')->assertJsonMissingPath('detail');
});

it('maps method not allowed', function (): void {
    $this->deleteJson('/v1/ping')->assertStatus(405)->assertJsonPath('code', 'method_not_allowed');
});

it('maps rate limiting and keeps the Retry-After header', function (): void {
    $this->getJson('/v1/test-errors/throttled')->assertOk();

    $this->getJson('/v1/test-errors/throttled')
        ->assertStatus(429)
        ->assertJsonPath('code', 'rate_limited')
        ->assertHeader('Retry-After');
});

it('renders problem details for /v1 even when the client does not ask for JSON', function (): void {
    $this->get('/v1/does-not-exist')
        ->assertStatus(404)
        ->assertHeader('Content-Type', 'application/problem+json');
});

it('gives every error code a title and an error status', function (ErrorCode $code): void {
    expect($code->title())->not->toBeEmpty()
        ->and($code->status())->toBeGreaterThanOrEqual(400);
})->with(ErrorCode::cases());
