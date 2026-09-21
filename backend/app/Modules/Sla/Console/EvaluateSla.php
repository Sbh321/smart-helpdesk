<?php

declare(strict_types=1);

namespace App\Modules\Sla\Console;

use App\Modules\Sla\Jobs\EvaluateSlaTimers;
use Illuminate\Console\Command;

final class EvaluateSla extends Command
{
    protected $signature = 'sla:evaluate';

    protected $description = 'Evaluate due SLA warnings and breaches for active workspaces';

    public function handle(): int
    {
        EvaluateSlaTimers::dispatch()->onQueue('sla');

        return self::SUCCESS;
    }
}
