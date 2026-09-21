<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports\Catalogue;

use App\Modules\Reporting\Reports\Dimension;
use App\Modules\Reporting\Reports\Measure;
use App\Modules\Reporting\Reports\ReportParameters;
use App\Modules\Reporting\Reports\SqlReport;
use DateTimeInterface;

/**
 * RPT-A06 Skill coverage per skill: the agents holding it now (by level), the tickets created in the
 * period whose category requires it, and the automatic assignment runs of the period that found no
 * eligible agent for such a ticket. Agent rows are current state, so the period does not narrow them.
 */
final class SkillCoverage extends SqlReport
{
    public function key(): string
    {
        return 'rpt-a06';
    }

    public function title(): string
    {
        return 'Skill coverage';
    }

    public function description(): string
    {
        return 'Agents per skill and level, tickets needing each skill and runs with no eligible agent.';
    }

    public function group(): string
    {
        return 'agents';
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return ['agents.view', 'tickets.view'];
    }

    public function defaultDimension(): string
    {
        return 'skill';
    }

    public function chart(): string
    {
        return 'table';
    }

    protected function source(): string
    {
        // Agent rows carry the period start as their time, so the period clause keeps them.
        return <<<'SQL'
            (
                SELECT s.tenant_id, s.skill_id, ?::timestamptz AS at, 'agent' AS kind, s.agent_profile_id AS ref, s.level
                FROM agent_skills s WHERE s.tenant_id = ?
                UNION ALL
                SELECT t.tenant_id, cs.skill_id, t.created_at, 'ticket', t.id, NULL
                FROM tickets t JOIN category_skill cs ON cs.tenant_id = t.tenant_id AND cs.category_id = t.category_id
                WHERE t.tenant_id = ?
                UNION ALL
                SELECT ta.tenant_id, cs.skill_id, ta.created_at, 'no_eligible', ta.ticket_id, NULL
                FROM ticket_assignments ta
                JOIN tickets t ON t.tenant_id = ta.tenant_id AND t.id = ta.ticket_id
                JOIN category_skill cs ON cs.tenant_id = t.tenant_id AND cs.category_id = t.category_id
                WHERE ta.tenant_id = ? AND ta.explanation ->> 'outcome' = 'no_eligible_agent'
            ) k
            SQL;
    }

    protected function sourceBindings(ReportParameters $parameters, DateTimeInterface $from, DateTimeInterface $to): array
    {
        return [$from, $parameters->tenantId, $parameters->tenantId, $parameters->tenantId];
    }

    protected function tenantColumn(): string
    {
        return 'k.tenant_id';
    }

    protected function periodColumn(): string
    {
        return 'k.at';
    }

    public function dimensions(): array
    {
        return [
            'skill' => new Dimension('Skill', 'k.skill_id', 'skills'),
        ];
    }

    public function measures(): array
    {
        $level = fn (string $label, string $condition): Measure => new Measure($label, "count(DISTINCT k.ref) FILTER (WHERE k.kind = 'agent' AND {$condition})");

        return [
            'agents' => $level('Agents holding the skill', 'true'),
            'agents_level_1_2' => $level('Agents at level 1–2', 'k.level <= 2'),
            'agents_level_3' => $level('Agents at level 3', 'k.level = 3'),
            'agents_level_4_5' => $level('Agents at level 4–5', 'k.level >= 4'),
            'average_level' => new Measure('Average level', "round(avg(k.level) FILTER (WHERE k.kind = 'agent'), 1)", 'number'),
            'tickets' => new Measure('Tickets needing the skill', "count(DISTINCT k.ref) FILTER (WHERE k.kind = 'ticket')"),
            'no_eligible_agent' => Measure::count('No eligible agent', "k.kind = 'no_eligible'"),
        ];
    }
}
