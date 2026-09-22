<?php

declare(strict_types=1);

namespace App\Modules\Mail\Support;

use App\Modules\Contacts\Models\Contact;
use App\Modules\Mail\Domain\EmailAddress;
use App\Modules\Mail\Domain\InboundAddresses;
use App\Modules\Mail\Domain\ParsedEmail;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tickets\Models\Ticket;

/**
 * Routes an inbound message (docs/04-domain/email.md §Routing). Rules, first match wins:
 *
 * 1. `ticket+<uuid>@` in To, Cc, Delivered-To or X-Original-To → that ticket;
 * 2. `In-Reply-To`, then `References` (newest first), naming one of our ticket Message-IDs → that ticket;
 * 3. `support+<slug>@` of an active workspace → a new ticket there;
 * 4. otherwise unrouted, a platform row.
 *
 * A ticket found by rule 1 or 2 is trusted only after two checks inside its own workspace: if the
 * message also names an intake address, it must be that workspace's (a forged plus-address for
 * another workspace's ticket is `tenant_mismatch`), and the sender must be the ticket's requester or
 * an active contact of the ticket's organisation (`sender_not_allowed`). The address never selects
 * the workspace by itself: the ticket id is looked up per workspace, under row-level security.
 */
final readonly class InboundRouter
{
    public function route(ParsedEmail $email): InboundRoute
    {
        $sender = $email->from;
        if ($sender === null) {
            return InboundRoute::unrouted('no_sender');
        }

        $candidates = (new InboundAddresses((string) config('helpdesk.hosts.mail')))->candidates($email);

        foreach (['plus_address' => $candidates->plusAddressTickets, 'thread' => $candidates->threadTickets] as $route => $ticketIds) {
            foreach ($ticketIds as $ticketId) {
                $tenant = $this->workspaceOfTicket($ticketId);
                if ($tenant === null) {
                    continue;
                }

                if ($candidates->intakeWorkspaces !== [] && ! in_array($tenant->slug, $candidates->intakeWorkspaces, true)) {
                    return InboundRoute::rejected($tenant, $route, $ticketId, 'tenant_mismatch');
                }

                $author = $tenant->run(fn (): ?string => $this->author($ticketId, $sender));

                return $author === null
                    ? InboundRoute::rejected($tenant, $route, $ticketId, 'sender_not_allowed')
                    : InboundRoute::ticket($tenant, $route, $ticketId, $author);
            }
        }

        foreach ($candidates->intakeWorkspaces as $slug) {
            $tenant = Tenant::findBySlug($slug);
            if ($tenant instanceof Tenant && $tenant->isActive()) {
                return InboundRoute::newTicket($tenant, 'intake');
            }
        }

        return InboundRoute::unrouted($candidates->isEmpty() ? 'no_route' : 'unknown_target');
    }

    /**
     * The active workspace that holds the ticket. Each workspace is asked inside its own context, so
     * the lookup obeys row-level security; a UUID v7 exists in at most one of them.
     */
    private function workspaceOfTicket(string $ticketId): ?Tenant
    {
        // MVP-SHORTCUT: one indexed lookup per active workspace; V1: V1-ML-07 (a platform-level ticket-id directory).
        foreach (Tenant::query()->active()->orderBy('created_at')->cursor() as $tenant) {
            if ($tenant->run(fn (): bool => Ticket::query()->whereKey($ticketId)->exists())) {
                return $tenant;
            }
        }

        return null;
    }

    /** The contact allowed to write on the ticket as `$sender`, or null. Runs inside the ticket's workspace. */
    private function author(string $ticketId, EmailAddress $sender): ?string
    {
        // MVP-SHORTCUT: the From address is trusted without the mail server's SPF/DKIM/DMARC verdict; V1: V1-ML-08.
        $ticket = Ticket::query()->with('contact')->find($ticketId);
        if ($ticket === null) {
            return null;
        }

        $requester = $ticket->contact;
        if ($requester instanceof Contact && strtolower($requester->email) === $sender->address && ! $requester->isArchived()) {
            return $requester->id;
        }

        $colleague = Contact::findByEmail($sender->address);

        return $colleague !== null
            && ! $colleague->isArchived()
            && $ticket->organization_id !== null
            && $colleague->organization_id === $ticket->organization_id
                ? $colleague->id
                : null;
    }
}
