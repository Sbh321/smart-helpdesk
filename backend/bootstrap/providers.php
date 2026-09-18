<?php

declare(strict_types=1);

use App\Modules\Agents\AgentsServiceProvider;
use App\Modules\Audit\AuditServiceProvider;
use App\Modules\Automation\AutomationServiceProvider;
use App\Modules\Contacts\ContactsServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Integrations\IntegrationsServiceProvider;
use App\Modules\Mail\MailServiceProvider;
use App\Modules\Media\MediaServiceProvider;
use App\Modules\Notifications\NotificationsServiceProvider;
use App\Modules\Platform\PlatformServiceProvider;
use App\Modules\Reporting\ReportingServiceProvider;
use App\Modules\Sla\SlaServiceProvider;
use App\Modules\Tenancy\TenancyServiceProvider;
use App\Modules\Tickets\TicketsServiceProvider;
use App\Providers\ApiDocsServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AgentsServiceProvider::class,
    AuditServiceProvider::class,
    AutomationServiceProvider::class,
    ContactsServiceProvider::class,
    IdentityServiceProvider::class,
    IntegrationsServiceProvider::class,
    MailServiceProvider::class,
    MediaServiceProvider::class,
    NotificationsServiceProvider::class,
    PlatformServiceProvider::class,
    ReportingServiceProvider::class,
    SlaServiceProvider::class,
    TenancyServiceProvider::class,
    TicketsServiceProvider::class,
    ApiDocsServiceProvider::class,
    AppServiceProvider::class,
    HorizonServiceProvider::class,
];
