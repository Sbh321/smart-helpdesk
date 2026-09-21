<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Http\Controllers;

use App\Modules\Audit\Audit;
use App\Modules\Contacts\Actions\SaveContact;
use App\Modules\Contacts\Http\Requests\ContactRequest;
use App\Modules\Contacts\Http\Requests\IndexContactsRequest;
use App\Modules\Contacts\Http\Resources\ContactResource;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Queries\ContactListQuery;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Contacts')]
final class ContactController
{
    /**
     * List contacts.
     *
     * Archived contacts are hidden unless `filter[archived]` is `true` or `all`.
     */
    #[QueryParameter('filter[organization_id]', 'Organisation ids, or `none`, comma separated.', type: 'string')]
    #[QueryParameter('filter[tag]', 'Tag slugs, comma separated.', type: 'string')]
    #[QueryParameter('filter[archived]', '`false` (default), `true` or `all`.', type: 'string')]
    public function index(IndexContactsRequest $request): AnonymousResourceCollection
    {
        return ContactResource::collection((new ContactListQuery($request))->paginate());
    }

    /**
     * Find contacts as you type.
     *
     * Fuzzy match on name and email (trigram similarity), best matches first, at most 10.
     */
    public function typeahead(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate(['q' => ['required', 'string', 'min:1', 'max:100']]);
        $term = (string) $validated['q'];
        $pattern = '%'.addcslashes($term, '%_\\').'%';

        $contacts = Contact::query()
            ->with(['organization', 'tags'])
            ->active()
            ->where(fn ($query) => $query
                ->where('name', 'ilike', $pattern)
                ->orWhere('email', 'ilike', $pattern)
                ->orWhereRaw('name % ?', [$term])
                ->orWhereRaw('email % ?', [$term]))
            ->orderByRaw('greatest(similarity(name, ?), similarity(email, ?)) DESC', [$term, $term])
            ->orderBy('name')
            ->limit(10)
            ->get();

        return ContactResource::collection($contacts);
    }

    /**
     * Create a contact.
     */
    #[Response(status: 201, type: ContactResource::class)]
    public function store(ContactRequest $request, SaveContact $save): JsonResponse
    {
        $contact = $save($request->validated());
        Audit::record('contact.created', $contact);

        return (new ContactResource($contact))->response()->setStatusCode(201);
    }

    /**
     * Show a contact.
     */
    public function show(Contact $contact): ContactResource
    {
        return new ContactResource($contact->load(['organization', 'tags']));
    }

    /**
     * Update a contact.
     */
    public function update(ContactRequest $request, Contact $contact, SaveContact $save): ContactResource
    {
        return new ContactResource($save($request->validated(), $contact));
    }

    /**
     * Archive a contact.
     *
     * Archived contacts leave the default list but keep their tickets.
     */
    public function archive(Contact $contact): ContactResource
    {
        if (! $contact->isArchived()) {
            $contact->forceFill(['archived_at' => now()])->save();
            Audit::record('contact.archived', $contact);
        }

        return new ContactResource($contact->load(['organization', 'tags']));
    }

    /**
     * Restore an archived contact.
     */
    public function unarchive(Contact $contact): ContactResource
    {
        if ($contact->isArchived()) {
            $contact->forceFill(['archived_at' => null])->save();
            Audit::record('contact.unarchived', $contact);
        }

        return new ContactResource($contact->load(['organization', 'tags']));
    }
}
