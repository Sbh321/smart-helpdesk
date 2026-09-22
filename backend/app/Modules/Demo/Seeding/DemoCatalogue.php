<?php

declare(strict_types=1);

namespace App\Modules\Demo\Seeding;

/**
 * The literal identities of the demo dataset (roadmap/11-demo-dataset.md): workspaces, people, teams,
 * skills, categories, organisations, contacts and the anchor tickets. Nothing here is random, so the
 * demo script can name them.
 */
final class DemoCatalogue
{
    public const string ACME = 'acme';

    public const string GLOBEX = 'globex';

    /** The workspaces `demo:reset` drops and rebuilds; no other workspace is ever touched. */
    public const array WORKSPACES = [self::ACME => 'Acme Support', self::GLOBEX => 'Globex Retail Support'];

    public const string TIMEZONE = 'Asia/Kathmandu';

    public const string PLATFORM_ADMIN = 'admin@platform.test';

    /** Staff who are not in the agent roster: email => [name, role]. */
    public const array STAFF = [
        'meera@acme.test' => ['Meera Joshi', 'owner'],
        'dev@acme.test' => ['Dev Malla', 'developer'],
        'priya@acme.test' => ['Priya Shah', 'manager'],
    ];

    public const array SKILLS = [
        'billing' => 'Billing',
        'refunds' => 'Refunds',
        'technical' => 'Technical',
        'networking' => 'Networking',
        'accounts' => 'Accounts',
        'onboarding' => 'Onboarding',
    ];

    public const array TEAMS = [
        'billing' => ['Billing', 'Invoices, payments and refunds.'],
        'technical' => ['Technical', 'Product faults, integrations and connectivity.'],
        'success' => ['Customer Success', 'Accounts, access and onboarding.'],
    ];

    /**
     * key => [name, required skills, default team, text bank of the experiments' generator].
     */
    public const array CATEGORIES = [
        'billing' => ['Billing question', ['billing'], 'billing', 'billing'],
        'refund' => ['Refund request', ['billing', 'refunds'], 'billing', 'billing'],
        'technical' => ['Technical issue', ['technical'], 'technical', 'technical'],
        'network' => ['Network / connectivity', ['technical', 'networking'], 'technical', 'network'],
        'account' => ['Account access', ['accounts'], 'success', 'account'],
        'onboarding' => ['Onboarding', ['onboarding'], 'success', 'general'],
    ];

    /**
     * email => [name, teams, skills (slug => level), capacity, availability at the end of the replay].
     * Priya (manager) also has an Agent profile without skills, so she can be picked by hand only.
     */
    public const array AGENTS = [
        'arjun@acme.test' => ['Arjun Thapa', ['technical'], ['technical' => 3, 'networking' => 2], 12, 'available'],
        'asha@acme.test' => ['Asha Gurung', ['billing'], ['billing' => 3, 'refunds' => 2], 10, 'available'],
        'bikram@acme.test' => ['Bikram Rai', ['billing'], ['billing' => 2, 'refunds' => 3], 10, 'available'],
        'chen@acme.test' => ['Chen Wei', ['billing', 'success'], ['billing' => 3, 'accounts' => 2], 12, 'available'],
        'deepa@acme.test' => ['Deepa Karki', ['technical'], ['technical' => 2, 'networking' => 3], 14, 'available'],
        'elena@acme.test' => ['Elena Rossi', ['success'], ['accounts' => 3, 'onboarding' => 3], 8, 'away'],
        'farid@acme.test' => ['Farid Haddad', ['technical', 'success'], ['technical' => 1, 'onboarding' => 2, 'accounts' => 1], 10, 'available'],
        'grace@acme.test' => ['Grace Lim', ['success'], ['onboarding' => 3, 'accounts' => 1], 10, 'offline'],
    ];

    /** key => [name, domain, tier at the start of the replay]. Hooli is upgraded to premium on day −20. */
    public const array ORGANISATIONS = [
        'globex' => ['Globex Retail', 'globex-retail.test', 'enterprise'],
        'initech' => ['Initech', 'initech.test', 'premium'],
        'umbrella' => ['Umbrella Health', 'umbrella-health.test', 'enterprise'],
        'hooli' => ['Hooli', 'hooli.test', 'standard'],
        'stark' => ['Stark Logistics', 'stark-logistics.test', 'premium'],
        'wayne' => ['Wayne Foods', 'wayne-foods.test', 'standard'],
    ];

    /** organisation key (or '' for none) => names; email is first.last@domain. */
    public const array CONTACTS = [
        'globex' => ['Aarav Sharma', 'Bianca Costa', 'Carlos Mendez', 'Divya Nair', 'Emil Novak'],
        'initech' => ['Fatima Khan', 'George Miller', 'Hana Sato', 'Rahul Verma', 'Julia Weber'],
        'umbrella' => ['Kiran Adhikari', 'Laura Schmidt', 'Mohan Das', 'Nina Petrova', 'Oscar Lindqvist'],
        'hooli' => ['Pooja Bista', 'Quentin Dubois', 'Rita Lama', 'Samir Haddad', 'Tara Pandey'],
        'stark' => ['Usha Rana', 'Victor Ahmed', 'Wendy Clark', 'Xavier Ortiz', 'Yuki Tanaka'],
        'wayne' => ['Zara Ali', 'Anil Shrestha', 'Beatriz Silva', 'Chloe Martin', 'Daniel Kim'],
        '' => ['Ethan Brooks', 'Freya Olsen', 'Gopal Poudel'],
    ];

    /** The contact who moves from Initech to Hooli (day −25) and on to Stark Logistics (day −12). */
    public const string MOVING_CONTACT = 'Rahul Verma';

    /** Live tickets (last 30 days): numbers 1001–1120, created in number order. */
    public const int FIRST_LIVE_NUMBER = 1001;

    public const int LIVE_TICKETS = 120;

    /** The OAuth client of the developer platform step and its scopes. */
    public const string API_CLIENT = 'Monitoring bridge';

    public const array API_CLIENT_SCOPES = ['tickets:read', 'tickets:write'];

    public const string WEBHOOK_URL = 'http://webhook-echo:9100/hook';

    public const array WEBHOOK_EVENTS = ['ticket.created', 'ticket.status_changed', 'ticket.resolved', 'ticket.sla_breached'];

    /**
     * The workspace's SLA policy after Meera's edit on day −30 (minutes: first response, resolution).
     * Before it the provisioning defaults apply.
     */
    public const array SLA_TARGETS = [
        'P1' => [30, 480],
        'P2' => [120, 1440],
        'P3' => [480, 4320],
        'P4' => [1440, 10080],
    ];

    /** Title and description the demo types in step 3 (docs/12-academic/demo-plan.md). */
    public const string GOLDEN_TITLE = 'Cannot login after password reset';

    public const string GOLDEN_DESCRIPTION = 'After the password reset the portal login fails with ERR-401 and I cannot log in.';

    /**
     * Anchor tickets: number => [title, description, category, contact, impact, urgency].
     * Their timelines are in DemoPlan::SCRIPTED.
     */
    public const array ANCHORS = [
        1031 => ['Login fails after resetting password (ERR-401)', 'Since the password reset the portal login fails with ERR-401. I cannot log in to the portal at all.', 'account', 'Laura Schmidt', 1, 2],
        1032 => ['Cannot log in after password reset — ERR-401 on portal', 'The portal login shows ERR-401 after I reset my password this morning.', 'account', 'Hana Sato', 2, 3],
        1040 => ['Invoice PDF downloads as a blank page', 'The invoice PDF for August downloads but every page is blank.', 'billing', 'George Miller', 2, 2],
        1041 => ['Blank PDF when downloading invoice', 'The invoice PDF downloads as a blank page since August.', 'billing', 'Wendy Clark', 2, 2],
        1055 => ['VPN disconnects every 10 minutes on Windows 11', 'Since the Windows 11 update the VPN disconnects every 10 minutes for the sales team.', 'network', 'Mohan Das', 3, 3],
        1056 => ['Windows 11 VPN keeps disconnecting every 10 minutes', 'Since the Windows 11 update the VPN keeps disconnecting every 10 minutes on our laptops.', 'network', 'Victor Ahmed', 2, 3],
        1060 => ['Refund for duplicate charge on 12 Sep', 'Our card was charged twice for the September subscription on 12 Sep. Please refund the duplicate charge.', 'refund', 'Zara Ali', 2, 2],
        1061 => ['Charged twice on 12 September, requesting refund', 'We see a duplicate charge for the subscription on 12 September and request a refund.', 'refund', 'Pooja Bista', 2, 2],
        1072 => ['Two-factor code SMS never arrives', 'The two-factor SMS code never arrives, so nobody in finance can sign in.', 'account', 'Divya Nair', 2, 3],
        1073 => ['Two-factor SMS code not arriving', 'The two-factor SMS code never arrives on any phone, so finance cannot sign in.', 'account', 'Tara Pandey', 2, 3],
        1080 => ['API returns 500 on POST /orders since v2.3.1', 'After upgrading to v2.3.1 every POST /orders call returns a 500 error.', 'technical', 'Emil Novak', 3, 3],
        1081 => ['POST /orders 500 error after upgrading to v2.3.1', 'Our integration gets a 500 error on POST /orders since the v2.3.1 upgrade.', 'technical', 'Xavier Ortiz', 3, 3],
        1090 => ['Complete outage: dashboard unreachable for all users', 'Nobody in the company can reach the dashboard; every page times out.', 'technical', 'Aarav Sharma', 4, 4],
        1095 => ['Typo on the pricing page footer', 'The footer of the pricing page says "Pricng".', 'onboarding', 'Anil Shrestha', 1, 1],
        1101 => ['Payment gateway timeout at checkout for EU customers', 'EU customers get a gateway timeout at checkout; payments are not taken.', 'technical', 'Bianca Costa', 4, 3],
        1102 => ['Data export stuck at 99 % for three days', 'The monthly data export has been stuck at 99 % for three days.', 'technical', 'Julia Weber', 2, 2],
        1103 => ['Wrong VAT rate applied to Irish invoices', 'Invoices to Irish customers use 20 % VAT instead of 23 %.', 'billing', 'Beatriz Silva', 2, 2],
        1104 => ['Cannot add new team members — seat limit error', 'Adding a new team member fails with "seat limit reached" although we have free seats.', 'account', 'Nina Petrova', 3, 4],
        1105 => ['Onboarding call never scheduled after signup', 'We signed up last week and the onboarding call was never scheduled.', 'onboarding', 'Yuki Tanaka', 3, 3],
        1110 => ['Reopened: refund not received after approval', 'The refund was approved but has not reached our account.', 'refund', 'Chloe Martin', 2, 2],
    ];

    /** Globex Retail Support: the second workspace, small on purpose (isolation step). */
    public const array GLOBEX_STAFF = [
        'sam@globex.test' => ['Sam Carter', 'admin'],
        'lina@globex.test' => ['Lina Park', 'agent'],
    ];

    public const array GLOBEX_CONTACTS = ['Maya Fischer', 'Noah Evans', 'Olivia Brown', 'Peter Novak', 'Rosa Diaz'];

    /** [title, description, impact, urgency]. */
    public const array GLOBEX_TICKETS = [
        ['Store POS terminal rejects contactless cards', 'Two tills in the Leeds store reject every contactless card.', 3, 3],
        ['Gift card balance shows zero', 'A customer gift card shows a zero balance after one purchase.', 2, 2],
        ['Weekly sales report missing Sunday', 'The weekly sales report stops at Saturday.', 2, 1],
        ['Price labels printer offline', 'The shelf label printer in aisle 4 is offline.', 1, 2],
        ['Loyalty points not credited', 'Loyalty points from online orders are not credited.', 2, 2],
        ['Click and collect emails delayed', 'Click and collect ready emails arrive hours late.', 2, 3],
        ['Returns desk cannot scan receipts', 'The returns desk scanner does not read the new receipts.', 2, 2],
        ['Staff rota app crashes on login', 'The staff rota app closes right after login on Android.', 1, 2],
    ];
}
