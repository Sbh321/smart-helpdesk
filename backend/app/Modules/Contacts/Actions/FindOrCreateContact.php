<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Actions;

use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\Organization;

/**
 * The contact behind an email address in the current workspace, created when the workspace allows it
 * (docs/04-domain/contacts.md, docs/04-domain/email.md §Unknown senders). A new contact is named after
 * the display name (or the address's local part) and, when `$matchOrganisation` is on, joins the
 * organisation whose `domain` equals the address's domain.
 */
final readonly class FindOrCreateContact
{
    public function __construct(private SaveContact $save) {}

    /**
     * @return array{contact: Contact|null, created: bool}
     */
    public function __invoke(string $email, ?string $name, bool $create, bool $matchOrganisation): array
    {
        $existing = Contact::findByEmail($email);
        if ($existing !== null || ! $create) {
            return ['contact' => $existing, 'created' => false];
        }

        $email = strtolower(trim($email));
        $at = strrpos($email, '@');
        $domain = $at === false ? '' : substr($email, $at + 1);
        $organisation = $matchOrganisation && $domain !== ''
            ? Organization::query()->whereRaw('lower(domain) = ?', [$domain])->orderBy('name')->first()
            : null;
        $display = trim((string) $name);

        $contact = ($this->save)([
            'name' => mb_substr($display === '' ? substr($email, 0, $at === false ? null : $at) : $display, 0, 120),
            'email' => $email,
            'organization_id' => $organisation?->id,
        ]);

        return ['contact' => $contact, 'created' => true];
    }
}
