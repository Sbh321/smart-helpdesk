<?php

declare(strict_types=1);

namespace App\Modules\Contacts;

use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\Organization;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;

final class ContactsServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        // Short, stable names in polymorphic columns (taggables.taggable_type, entity_changes).
        // Not enforced globally: framework tables such as model_has_roles keep class names.
        Relation::morphMap([
            'contact' => Contact::class,
            'organization' => Organization::class,
        ]);
    }
}
