<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Actions;

use App\Modules\Contacts\Models\Contact;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a contact and its tags in one transaction. The email keeps the case the user
 * typed; uniqueness is case-insensitive (docs/04-domain/contacts.md).
 */
final class SaveContact
{
    /**
     * @param  array<string, mixed>  $data  validated ContactRequest data
     */
    public function __invoke(array $data, ?Contact $contact = null): Contact
    {
        return DB::transaction(function () use ($data, $contact): Contact {
            $contact ??= new Contact;

            $contact->fill(Arr::except($data, ['tags']));
            $contact->save();

            if (array_key_exists('tags', $data)) {
                /** @var list<string> $tags */
                $tags = $data['tags'];
                $contact->syncTagNames($tags);
            }

            return $contact->load(['organization', 'tags']);
        });
    }
}
