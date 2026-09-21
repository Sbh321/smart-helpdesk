<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Domain;

/**
 * One API scope with its description and the permissions it grants.
 */
final readonly class ScopeDefinition
{
    /**
     * @param  list<string>  $permissions
     */
    public function __construct(
        public string $scope,
        public string $description,
        public array $permissions,
    ) {}
}
