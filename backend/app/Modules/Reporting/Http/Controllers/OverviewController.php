<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Reporting\Http\Resources\EntityOverviewResource;
use App\Modules\Reporting\Overviews\EntityOverview;
use App\Modules\Reporting\Overviews\EntityOverviews;
use Dedoc\Scramble\Attributes\Group;

/**
 * `GET /v1/{entity}/{id}/overview`: the 360 metrics, related records and trends of one record
 * (docs/07-api/conventions.md §Reports). Each route needs the entity's view permission.
 */
#[Group('Reports')]
final class OverviewController
{
    public function __construct(private readonly EntityOverviews $overviews) {}

    /** Get a ticket overview. */
    public function ticket(string $id): EntityOverviewResource
    {
        return $this->answer($this->overviews->ticket($id));
    }

    /** Get a contact overview. */
    public function contact(string $id): EntityOverviewResource
    {
        return $this->answer($this->overviews->contact($id));
    }

    /** Get an organisation overview. */
    public function organization(string $id): EntityOverviewResource
    {
        return $this->answer($this->overviews->organization($id));
    }

    /** Get an agent overview. */
    public function agent(string $id): EntityOverviewResource
    {
        return $this->answer($this->overviews->agent($id));
    }

    /** Get a team overview. */
    public function team(string $id): EntityOverviewResource
    {
        return $this->answer($this->overviews->team($id));
    }

    /** Get a category overview. */
    public function category(string $id): EntityOverviewResource
    {
        return $this->answer($this->overviews->category($id));
    }

    /** @param array<string, mixed>|null $overview */
    private function answer(?array $overview): EntityOverviewResource
    {
        abort_if($overview === null, 404);

        /** @var array{entity: string, id: string, title: string, metrics: array<string, int|float|string|null>, related: array<string, mixed>, trends: array<string, list<array<string, mixed>>>} $overview */
        return new EntityOverviewResource(EntityOverview::from($overview));
    }
}
