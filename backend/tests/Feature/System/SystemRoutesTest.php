<?php

declare(strict_types=1);

it('answers the ping route under /v1', function (): void {
    $this->getJson('/v1/ping')
        ->assertOk()
        ->assertExactJson(['data' => ['status' => 'ok']]);
});

it('serves the container health check at /up', function (): void {
    $this->get('/up')->assertOk();
});

it('does not expose the old /api prefix', function (): void {
    $this->getJson('/api/ping')->assertNotFound();
});
