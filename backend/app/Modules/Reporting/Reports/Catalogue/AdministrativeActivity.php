<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports\Catalogue;

use App\Modules\Reporting\Reports\Dimension;
use App\Modules\Reporting\Reports\Filter;
use App\Modules\Reporting\Reports\Measure;
use App\Modules\Reporting\Reports\SqlReport;

/**
 * RPT-G01 Administrative activity: the workspace's audit log entries in the period by action, actor or
 * date, with role and permission changes counted apart.
 */
final class AdministrativeActivity extends SqlReport
{
    private const string ACCESS_CHANGE = "a.action LIKE 'role.%' OR a.action = 'user.role_changed'";

    public function key(): string
    {
        return 'rpt-g01';
    }

    public function title(): string
    {
        return 'Administrative activity';
    }

    public function description(): string
    {
        return 'Audit log actions by action, actor and date, with role and permission changes.';
    }

    public function group(): string
    {
        return 'administration';
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return ['audit.view'];
    }

    public function defaultDimension(): string
    {
        return 'action';
    }

    protected function orderBy(): string
    {
        return 'count(*) DESC, 1';
    }

    protected function source(): string
    {
        return 'audit_logs a';
    }

    protected function tenantColumn(): string
    {
        return 'a.tenant_id';
    }

    protected function periodColumn(): string
    {
        return 'a.created_at';
    }

    public function dimensions(): array
    {
        return [
            'action' => new Dimension('Action', 'a.action'),
            'actor' => new Dimension('Actor', 'a.actor_id', 'users'),
            'actor_type' => new Dimension('Actor type', 'a.actor_type', 'actor_type'),
            'day' => Dimension::day('a.created_at'),
            'week' => Dimension::week('a.created_at'),
        ];
    }

    public function measures(): array
    {
        return [
            'actions' => Measure::count('Actions'),
            'actors' => new Measure('Actors', 'count(DISTINCT a.actor_id)'),
            'access_changes' => Measure::count('Role and permission changes', self::ACCESS_CHANGE),
        ];
    }

    public function filters(): array
    {
        return [
            'action' => new Filter('Action', 'a.action'),
            'actor_type' => new Filter('Actor type', 'a.actor_type', labels: 'actor_type'),
        ];
    }
}
