<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Console;

use App\Modules\Contacts\Models\Contact;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tickets\Actions\CreateTicket;
use App\Modules\Tickets\Models\Category;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Laravel\Telescope\Telescope;

/**
 * Development data for the list performance check and the parallel numbering check
 * (roadmap M1-17 acceptance). Tickets go through CreateTicket, so numbers are real.
 * Not registered in production.
 */
final class SeedSampleTicketsCommand extends Command
{
    protected $signature = 'tickets:seed-sample
        {workspace : Workspace slug}
        {--count=100 : Tickets to create}
        {--contacts=50 : Contacts to make sure exist}';

    protected $description = 'Create sample contacts and tickets in a workspace (development only)';

    private const WORDS = [
        'printer', 'invoice', 'password', 'reset', 'login', 'vpn', 'email', 'outage', 'refund', 'laptop',
        'network', 'slow', 'error', 'payment', 'account', 'locked', 'upgrade', 'license', 'backup', 'crash',
    ];

    public function handle(CreateTicket $create): int
    {
        $tenant = Tenant::findBySlug((string) $this->argument('workspace'));

        if ($tenant === null) {
            $this->components->error('Unknown workspace.');

            return self::FAILURE;
        }

        $count = max(0, (int) $this->option('count'));

        // Telescope records every query in local; over thousands of tickets that exhausts memory.
        if (class_exists(Telescope::class)) {
            Telescope::stopRecording();
        }

        $tenant->run(function () use ($create, $count): void {
            $contacts = $this->ensureContacts(max(1, (int) $this->option('contacts')));
            $categories = Category::query()->active()->pluck('id')->all();

            if ($categories === []) {
                $categories = [Category::query()->create(['name' => 'General'])->id];
            }

            $bar = $this->output->createProgressBar($count);

            for ($i = 0; $i < $count; $i++) {
                $words = collect(self::WORDS)->random(4)->all();

                $create([
                    'title' => Str::ucfirst(implode(' ', array_slice($words, 0, 3))).' problem',
                    'description' => 'The '.implode(' and ', $words).' keeps failing since this morning.',
                    'contact_id' => $contacts[array_rand($contacts)],
                    'category_id' => $categories[array_rand($categories)],
                    'impact' => random_int(1, 4),
                    'urgency' => random_int(1, 4),
                ], null, 'seed');

                $bar->advance();
            }

            $bar->finish();
            $this->newLine();
        });

        $this->components->info("Created {$count} tickets in {$tenant->slug}.");

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function ensureContacts(int $wanted): array
    {
        $existing = Contact::query()->limit($wanted)->pluck('id')->all();

        for ($i = count($existing); $i < $wanted; $i++) {
            $existing[] = Contact::query()->create([
                'name' => fake()->name(),
                'email' => 'sample'.$i.'.'.Str::lower(Str::random(6)).'@example.test',
            ])->id;
        }

        return array_values($existing);
    }
}
