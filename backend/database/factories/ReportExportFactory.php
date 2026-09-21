<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Modules\Reporting\Models\ReportExport;
use App\Modules\Tenancy\Models\Tenant;
use Database\Factories\Concerns\ForTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReportExport> */
final class ReportExportFactory extends Factory
{
    use ForTenant;

    protected $model = ReportExport::class;

    public function definition(): array
    {
        return [
            'report_key' => 'rpt-t01',
            'parameters' => ['from' => '2026-09-01', 'to' => '2026-09-21'],
            'format' => 'csv',
            'state' => 'queued',
            // The requester is made in the export's own workspace; outside any workspace it stays null
            // and the insert fails on the NOT NULL column, as every tenant factory must.
            'requested_by_user_id' => function (array $attributes): ?string {
                $tenantId = $attributes['tenant_id'] ?? tenant()?->getTenantKey();

                return is_string($tenantId)
                    ? (string) User::factory()->forTenant(Tenant::query()->findOrFail($tenantId))->create()->getKey()
                    : null;
            },
        ];
    }
}
