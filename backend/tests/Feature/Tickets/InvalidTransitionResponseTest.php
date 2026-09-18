<?php

declare(strict_types=1);

use App\Modules\Platform\Actions\ProvisionTenant;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Models\Category;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

it('renders an invalid transition as 422 invalid_transition with the allowed targets', function (): void {
    // The transition endpoint arrives in M2; this proves the error contract the SPA relies on.
    Route::middleware('api')->post('/v1/test-transition', fn () => TicketStatus::Closed->transitionTo(TicketStatus::Pending));

    $this->postJson('/v1/test-transition')
        ->assertStatus(422)
        ->assertJson([
            'code' => 'invalid_transition',
            'detail' => 'A ticket cannot move from closed to pending.',
            'meta' => ['from' => 'closed', 'to' => 'pending', 'allowed' => ['in_progress']],
        ]);
});

it('seeds the default categories when a workspace is provisioned', function (): void {
    Notification::fake();

    $tenant = app(ProvisionTenant::class)('initech', 'Initech')['tenant'];
    $names = $tenant->run(fn () => Category::query()->orderBy('sort_order')->pluck('name')->all());

    expect($names)->toBe(config('helpdesk.tickets.default_categories'));
});
