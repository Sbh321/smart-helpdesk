<?php

declare(strict_types=1);

use App\Modules\Media\Health\StorageCheck;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Spatie\Health\Facades\Health;

function fakeCheck(string $name, bool $ok): Check
{
    $check = new class extends Check
    {
        public bool $ok = true;

        public function run(): Result
        {
            return $this->ok
                ? Result::make()->ok()->shortSummary('fine')
                : Result::make()->failed('It is down.')->shortSummary('down');
        }
    };
    $check->ok = $ok;

    return $check->name($name);
}

beforeEach(function (): void {
    config(['helpdesk.health.token' => 'health-secret']);
});

it('registers the dependency checks', function (): void {
    $names = Health::registeredChecks()->map(fn (Check $check): string => $check->getName())->all();

    expect($names)->toContain('Database', 'Redis', 'Cache', 'Storage', 'Queue', 'Schedule', 'Horizon', 'UsedDiskSpace');
});

it('refuses the report without a valid token', function (array $headers): void {
    $this->getJson('/v1/health', $headers)
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');
})->with([
    'no token' => [[]],
    'wrong token' => [['Authorization' => 'Bearer nope']],
]);

it('refuses the report when no token is configured outside local', function (): void {
    config(['helpdesk.health.token' => null]);

    $this->getJson('/v1/health', ['Authorization' => 'Bearer '])->assertForbidden();
});

it('returns 200 with every check when all pass', function (): void {
    Health::clearChecks()->checks([fakeCheck('Database', true), fakeCheck('Redis', true)]);

    $this->getJson('/v1/health', ['Authorization' => 'Bearer health-secret'])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.status', 'ok')
        ->assertJsonCount(2, 'data.checks')
        ->assertJsonPath('data.checks.0', [
            'name' => 'Database',
            'label' => 'Database',
            'status' => 'ok',
            'summary' => 'fine',
            'message' => null,
        ]);
});

it('returns 503 when any check fails', function (): void {
    Health::clearChecks()->checks([fakeCheck('Database', true), fakeCheck('Storage', false)]);

    $this->getJson('/v1/health', ['X-Health-Token' => 'health-secret'])
        ->assertStatus(503)
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.checks.1.status', 'failed')
        ->assertJsonPath('data.checks.1.message', 'It is down.');
});

it('reports warnings without failing the report', function (): void {
    $warning = new class extends Check
    {
        public function run(): Result
        {
            return Result::make()->warning('The disk is almost full (86% used).')->shortSummary('86%');
        }
    };
    Health::clearChecks()->checks([fakeCheck('Database', true), $warning->name('UsedDiskSpace')]);

    $this->getJson('/v1/health', ['X-Health-Token' => 'health-secret'])
        ->assertOk()
        ->assertJsonPath('data.status', 'warning');
});

it('passes the storage check on a writable local disk', function (): void {
    Health::clearChecks()->checks([StorageCheck::new()->disk('local')]);

    $this->getJson('/v1/health', ['X-Health-Token' => 'health-secret'])
        ->assertOk()
        ->assertJsonPath('data.checks.0.summary', 'writable');
});
