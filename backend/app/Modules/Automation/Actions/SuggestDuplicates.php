<?php

declare(strict_types=1);

namespace App\Modules\Automation\Actions;

use App\Modules\Automation\Contracts\DuplicateStrategy;
use App\Modules\Automation\Domain\Duplicates\DuplicateResult;
use App\Modules\Automation\Domain\Duplicates\DuplicateSettings;
use App\Modules\Automation\Domain\Duplicates\TicketText;
use App\Modules\Automation\Queries\DuplicateCandidates;
use App\Modules\Tenancy\Settings\Settings;

final readonly class SuggestDuplicates
{
    public function __construct(
        private DuplicateStrategy $strategy,
        private DuplicateCandidates $candidates,
        private Settings $settings,
    ) {}

    public function __invoke(TicketText $ticket): DuplicateResult
    {
        // The candidate query needs the same window and limit the strategy was built with.
        $settings = DuplicateSettings::fromArray((array) $this->settings->get('automation.duplicates.baseline'));

        return $this->strategy->find($ticket, $this->candidates->for($ticket, $settings));
    }
}
