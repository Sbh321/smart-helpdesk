<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Actions;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Next ticket number for a workspace, gapless under concurrency (docs/08-database/tenancy.md
 * §Per-tenant counters).
 *
 * One statement takes the counter row lock and returns the number, so concurrent creations queue
 * on that row. It must run inside the ticket-creation transaction: a rollback returns the number.
 * A workspace without a counter row (created outside provisioning) gets one on first use.
 */
final class AllocateTicketNumber
{
    public function __invoke(string $tenantId): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Ticket numbers must be allocated inside the creating transaction.');
        }

        $row = DB::selectOne(<<<'SQL'
            INSERT INTO tenant_counters (tenant_id, next_ticket_number) VALUES (?, 2)
            ON CONFLICT (tenant_id) DO UPDATE SET next_ticket_number = tenant_counters.next_ticket_number + 1
            RETURNING next_ticket_number - 1 AS number
            SQL, [$tenantId]);

        return (int) $row->number;
    }
}
