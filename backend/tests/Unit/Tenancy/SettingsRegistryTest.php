<?php

declare(strict_types=1);

use App\Modules\Tenancy\Settings\ArraySection;
use App\Modules\Tenancy\Settings\Sections\BrandingSection;
use App\Modules\Tenancy\Settings\SettingsRegistry;

it('finds the section that owns a key by its longest registered prefix', function (): void {
    $registry = new SettingsRegistry;
    $registry->register(new ArraySection('tickets', ['reopen_window_days' => 14], []));
    $registry->register(new ArraySection('automation.priority', ['baseline' => []], []));

    [$section, $path] = $registry->locate('automation.priority.baseline.weights');
    expect($section->key())->toBe('automation.priority')->and($path)->toBe('baseline.weights');

    [$section, $path] = $registry->locate('tickets');
    expect($section->key())->toBe('tickets')->and($path)->toBeNull()
        ->and(array_keys($registry->all()))->toBe(['automation.priority', 'tickets'])
        ->and($registry->has('automation'))->toBeFalse();
});

it('rejects keys and sections nobody registered', function (): void {
    $registry = new SettingsRegistry;

    expect(fn () => $registry->locate('billing.plan'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->get('billing'))->toThrow(InvalidArgumentException::class);
});

it('runs the cross-field check of an array section only when it has one', function (): void {
    $plain = new ArraySection('a', [], []);
    $checked = new ArraySection('b', [], [], fn (array $values): array => $values === [] ? ['x' => 'empty'] : []);

    expect($plain->check(['anything' => 1]))->toBe([])
        ->and($checked->check([]))->toBe(['x' => 'empty'])
        ->and($checked->check(['x' => 1]))->toBe([]);
});

it('computes WCAG contrast and accepts any colour that one of the two foregrounds can sit on', function (): void {
    expect(round(BrandingSection::contrast('#ffffff', '#000000'), 1))->toBe(21.0)
        ->and(round(BrandingSection::contrast('#0f766e', '#ffffff'), 2))->toBeGreaterThan(4.5)
        ->and(BrandingSection::contrast('#777777', '#777777'))->toBe(1.0);

    $branding = new BrandingSection;
    expect($branding->check(['primary' => '#0f766e']))->toBe([])
        ->and($branding->check(['primary' => '#ffdd00']))->toBe([])
        ->and($branding->check(['primary' => null]))->toBe([]);
});
