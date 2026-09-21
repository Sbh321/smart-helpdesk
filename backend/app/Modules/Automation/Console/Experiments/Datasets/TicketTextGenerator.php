<?php

declare(strict_types=1);

namespace App\Modules\Automation\Console\Experiments\Datasets;

use App\Support\Experiments\SeededRandom;

/**
 * Generated ticket texts for E2 (duplicate pairs and the 5 000-ticket haystack). Each category has a
 * small phrase bank; a ticket is an object, a symptom and two context sentences, sometimes with an
 * error code, a module and a number, so tickets of one category share words the way real ones do.
 */
final class TicketTextGenerator
{
    /** category => [objects, symptoms, context sentences with {object}] */
    private const array BANK = [
        'billing' => [
            ['invoice', 'payment', 'refund', 'subscription renewal', 'card charge', 'receipt', 'tax number', 'billing address', 'discount code', 'quarterly bill'],
            ['shows wrong amount', 'was charged twice', 'failed', 'is missing', 'cannot be downloaded', 'is overdue', 'was declined', 'has the wrong currency'],
            ['The {object} for this month does not match our contract.', 'Our finance team noticed the {object} while closing the books.', 'We tried again with another card but the {object} still fails.', 'The {object} page keeps loading and then shows a blank screen.', 'Accounts payable needs the {object} corrected before Friday.', 'The {object} email never arrived in our mailbox.', 'Our auditor asked for a copy of the {object}.'],
        ],
        'technical' => [
            ['dashboard', 'export', 'search', 'report builder', 'file upload', 'mobile app', 'integration', 'API', 'notification', 'calendar sync'],
            ['crashes', 'is very slow', 'returns an error', 'times out', 'shows old data', 'stopped working', 'freezes', 'loses changes'],
            ['Since the last update the {object} behaves differently.', 'The {object} works for small files but not for large ones.', 'Our developers see a server error when they call the {object}.', 'Clearing the browser cache did not fix the {object}.', 'The {object} problem happens on Chrome and Firefox.', 'Several colleagues report the same {object} issue.', 'The {object} spinner never finishes.'],
        ],
        'account' => [
            ['login', 'password reset', 'two-factor code', 'user invitation', 'profile', 'single sign-on', 'account lock', 'email change', 'role permissions', 'session'],
            ['does not work', 'link expired', 'is not received', 'keeps failing', 'is blocked', 'shows access denied', 'logs me out', 'cannot be changed'],
            ['A new employee cannot finish the {object} step.', 'The {object} worked yesterday but fails today.', 'I tried the {object} from a private window as well.', 'The {object} message says my account is not recognised.', 'Our administrator checked the {object} settings already.', 'Every attempt at the {object} ends on the start page.', 'The {object} code arrives after it has expired.'],
        ],
        'network' => [
            ['VPN', 'office wifi', 'internet connection', 'DNS', 'firewall rule', 'remote desktop', 'proxy', 'network drive', 'router', 'video call'],
            ['drops every few minutes', 'is unreachable', 'is very slow', 'blocks traffic', 'cannot connect', 'keeps disconnecting', 'has packet loss', 'resolves the wrong address'],
            ['The {object} problem affects the whole second floor.', 'Restarting the laptop did not help with the {object}.', 'The {object} works from home but not in the branch office.', 'Our monitoring shows the {object} failing since this morning.', 'Staff cannot reach shared folders because of the {object}.', 'The {object} lights are blinking orange.', 'Calls freeze whenever the {object} is busy.'],
        ],
        'hardware' => [
            ['laptop', 'printer', 'monitor', 'keyboard', 'docking station', 'scanner', 'headset', 'battery', 'desk phone', 'projector'],
            ['does not turn on', 'is broken', 'makes a loud noise', 'is not detected', 'overheats', 'prints blank pages', 'flickers', 'needs replacement'],
            ['The {object} in meeting room four stopped working.', 'I dropped the {object} and now it behaves strangely.', 'The {object} is still under warranty.', 'Plugging the {object} into another port did not help.', 'The {object} shows an orange warning light.', 'Our team shares this {object} and needs it today.', 'The {object} was replaced last year already.'],
        ],
        'general' => [
            ['training session', 'feature request', 'documentation', 'data retention', 'contract question', 'onboarding', 'holiday schedule', 'office move', 'licence count', 'feedback'],
            ['needs clarification', 'is out of date', 'is missing details', 'needs approval', 'has a typo', 'is urgent', 'needs scheduling', 'is unclear'],
            ['Could someone explain the {object} to our new team?', 'Our manager asked about the {object} in the weekly meeting.', 'The {object} page on the portal is confusing.', 'We would like an update on the {object} before next month.', 'The {object} was discussed with your sales team.', 'Please share the latest {object} with our office.', 'Legal wants the {object} in writing.'],
        ],
    ];

    private const array MODULES = ['portal', 'workspace', 'admin panel', 'finance module', 'branch office', 'head office', 'warehouse', 'call centre'];

    /** @return list<string> */
    public static function categories(): array
    {
        return array_keys(self::BANK);
    }

    /**
     * @return array{title: string, description: string, object: string}
     */
    public function ticket(SeededRandom $random, string $category, ?string $avoidObject = null): array
    {
        [$objects, $symptoms, $contexts] = self::BANK[$category];
        do {
            $object = $random->pick($objects);
        } while ($object === $avoidObject);

        $first = $random->pick($contexts);
        do {
            $second = $random->pick($contexts);
        } while ($second === $first);

        $sentences = [str_replace('{object}', $object, $first), str_replace('{object}', $object, $second)];
        if ($random->chance(0.4)) {
            $sentences[] = sprintf('The screen shows error ERR-%d.', $random->int(100, 999));
        }
        if ($random->chance(0.5)) {
            $sentences[] = sprintf('This happens in the %s for %d users.', $random->pick(self::MODULES), $random->int(2, 60));
        }

        return [
            'title' => ucfirst($object.' '.$random->pick($symptoms)),
            'description' => implode(' ', $sentences),
            'object' => $object,
        ];
    }
}
