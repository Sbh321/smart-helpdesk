<?php

declare(strict_types=1);

use App\Modules\Automation\Domain\Duplicates\DuplicateMatch;
use App\Modules\Automation\Domain\Duplicates\DuplicateSettings;
use App\Modules\Automation\Domain\Duplicates\TicketText;
use App\Modules\Automation\Domain\Exceptions\InvalidStrategySettings;
use App\Modules\Automation\Domain\Text\WordSet;
use App\Modules\Automation\Strategies\Baseline\JaccardDuplicates;
use App\Support\Attributes\AcademicBaseline;
use Carbon\CarbonImmutable;

function newLoginTicket(): TicketText
{
    return new TicketText('new', 'Cannot login after password reset', 'Login page shows error ERR-401 after I reset my password.');
}

// docs/05-algorithms/duplicate-detection.md §Worked example.
it('reproduces the worked example: 0.50 suggested, 0.15 ignored', function (): void {
    $candidates = [
        new TicketText('1031', 'Login fails after resetting password', 'Error ERR-401 on login page.'),
        new TicketText('1002', 'Invoice PDF is blank', 'The invoice download shows an empty page.'),
    ];
    $strategy = new JaccardDuplicates;
    $result = $strategy->find(newLoginTicket(), $candidates);

    expect($result->matches)->toHaveCount(1)
        ->and($result->matches[0]->ticketId)->toBe('1031')
        ->and($result->matches[0]->score())->toBe(0.5)
        ->and($result->matches[0]->sharedWords)->toBe(['err-401', 'error', 'login', 'page', 'password'])
        ->and($result->matches[0]->unionSize)->toBe(10)
        ->and($result->candidatesCompared)->toBe(2)
        ->and($result->explanation())->toBe([
            'strategy' => 'jaccard_duplicates',
            'strategy_version' => '1.0.0',
            'words' => ['cannot', 'login', 'password', 'reset', 'page', 'shows', 'error', 'err-401'],
            'candidates_compared' => 2,
            'settings' => ['threshold' => 0.35, 'max_suggestions' => 5, 'candidate_limit' => 50, 'window_days' => 30],
            'matches' => [[
                'ticket_id' => '1031', 'score' => 0.5,
                'shared_words' => ['err-401', 'error', 'login', 'page', 'password'], 'shared' => 5, 'union' => 10,
            ]],
        ]);

    // The ignored candidate scores 2 / 13 = 0.15 when the threshold lets it through.
    $all = (new JaccardDuplicates(new DuplicateSettings(threshold: 0.01)))->find(newLoginTicket(), $candidates);
    expect(round($all->matches[1]->score(), 2))->toBe(0.15)
        ->and($all->matches[1]->sharedWords)->toBe(['page', 'shows'])
        ->and($all->matches[1]->unionSize)->toBe(13)
        ->and($all->matches[1]->toArray()['score'])->toBe(0.1538);
});

it('scores 0 for disjoint and 1 for identical word sets', function (): void {
    $strategy = new JaccardDuplicates(new DuplicateSettings(threshold: 0.0001));
    $result = $strategy->find(newLoginTicket(), [
        new TicketText('same', 'cannot LOGIN after password reset!', 'login page shows error err-401'),
        new TicketText('other', 'Printer jammed', 'Paper stuck in tray'),
    ]);

    expect($result->matches)->toHaveCount(1)
        ->and($result->matches[0]->score())->toBe(1.0)
        ->and((new DuplicateMatch('x', [], 0))->score())->toBe(0.0)
        ->and((new DuplicateMatch('x', [], 5))->score())->toBe(0.0);
});

it('suggests a candidate exactly at the threshold', function (): void {
    // A has 4 words, B shares 2 of them and adds 0 → 2 / 4 = 0.5.
    $ticket = new TicketText('n', 'alpha bravo charlie delta');
    $candidate = new TicketText('c', 'alpha bravo');

    expect((new JaccardDuplicates(new DuplicateSettings(threshold: 0.5), new WordSet))->find($ticket, [$candidate])->matches)->toHaveCount(1)
        ->and((new JaccardDuplicates(new DuplicateSettings(threshold: 0.51), new WordSet))->find($ticket, [$candidate])->matches)->toBe([]);
});

it('orders by score, then newest, then id, and keeps the best five', function (): void {
    $day = static fn (int $d): CarbonImmutable => CarbonImmutable::parse("2026-09-{$d} 10:00", 'UTC');
    $ticket = new TicketText('n', 'alpha bravo charlie delta');
    $candidates = [
        new TicketText('half-old', 'alpha bravo', '', $day(1)),
        new TicketText('half-new', 'alpha bravo', '', $day(9)),
        new TicketText('half-b', 'alpha bravo', '', $day(5)),
        new TicketText('half-a', 'charlie delta', '', $day(5)),
        new TicketText('half-undated', 'alpha delta'),
        new TicketText('full', 'delta charlie bravo alpha', '', $day(2)),
        new TicketText('three-quarters', 'alpha bravo charlie', '', $day(3)),
    ];

    $result = (new JaccardDuplicates(wordSet: new WordSet))->find($ticket, $candidates);

    expect(array_map(static fn (DuplicateMatch $m): string => $m->ticketId, $result->matches))
        ->toBe(['full', 'three-quarters', 'half-new', 'half-a', 'half-b'])
        ->and($result->candidatesCompared)->toBe(7);

    $three = (new JaccardDuplicates(new DuplicateSettings(maxSuggestions: 3), new WordSet))->find($ticket, $candidates);
    expect($three->matches)->toHaveCount(3);
});

it('never suggests the ticket itself and handles empty texts', function (): void {
    $strategy = new JaccardDuplicates;
    $result = $strategy->find(newLoginTicket(), [newLoginTicket()]);

    expect($result->matches)->toBe([])
        ->and($result->candidatesCompared)->toBe(0)
        ->and($strategy->find(new TicketText('a', 'the and', ''), [new TicketText('b', 'for with', '')])->matches)->toBe([]);
});

it('reads its settings from the configuration array and validates them', function (): void {
    expect(DuplicateSettings::fromArray(['threshold' => 0.5, 'candidate_limit' => 20, 'window_days' => 7, 'max_suggestions' => 3])->toArray())
        ->toBe(['threshold' => 0.5, 'max_suggestions' => 3, 'candidate_limit' => 20, 'window_days' => 7])
        ->and(DuplicateSettings::fromArray([]))->toEqual(new DuplicateSettings)
        ->and(fn () => new DuplicateSettings(threshold: 0))->toThrow(InvalidStrategySettings::class)
        ->and(fn () => new DuplicateSettings(threshold: 1.5))->toThrow(InvalidStrategySettings::class)
        ->and(fn () => new DuplicateSettings(maxSuggestions: 0))->toThrow(InvalidStrategySettings::class)
        ->and(fn () => new DuplicateSettings(candidateLimit: 0))->toThrow(InvalidStrategySettings::class)
        ->and(fn () => new DuplicateSettings(windowDays: 0))->toThrow(InvalidStrategySettings::class);
});

it('is marked as an academic baseline', function (): void {
    expect((new ReflectionClass(JaccardDuplicates::class))->getAttributes(AcademicBaseline::class))->toHaveCount(1)
        ->and((string) (new ReflectionClass(JaccardDuplicates::class))->getDocComment())->toContain('@deprecated');
});
