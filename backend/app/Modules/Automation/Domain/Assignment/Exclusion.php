<?php

declare(strict_types=1);

namespace App\Modules\Automation\Domain\Assignment;

final readonly class Exclusion
{
    /**
     * @param  list<string>  $missingSkills  only for {@see ExclusionReason::MissingSkill}
     */
    public function __construct(
        public string $agentId,
        public ExclusionReason $reason,
        public array $missingSkills = [],
    ) {}

    /**
     * @return array{agent_id: string, reason: string, missing_skills?: list<string>}
     */
    public function toArray(): array
    {
        $row = ['agent_id' => $this->agentId, 'reason' => $this->reason->value];

        if ($this->missingSkills !== []) {
            $row['missing_skills'] = $this->missingSkills;
        }

        return $row;
    }
}
