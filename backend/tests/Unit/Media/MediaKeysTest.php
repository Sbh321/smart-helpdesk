<?php

declare(strict_types=1);

use App\Modules\Media\Support\MediaKeys;

const KEY_ID = '01920000-0000-7000-8000-00000000abcd';

it('builds the staging, original and variant keys of an item', function (): void {
    expect(MediaKeys::staging(KEY_ID, 'png'))->toBe('uploads/'.KEY_ID.'.png')
        ->and(MediaKeys::original(KEY_ID, 'png'))->toBe('media/'.KEY_ID.'/original.png')
        ->and(MediaKeys::variant(KEY_ID, 'thumb'))->toBe('media/'.KEY_ID.'/thumb.webp')
        ->and(MediaKeys::variant(KEY_ID, 'preview'))->toBe('media/'.KEY_ID.'/preview.webp')
        ->and(MediaKeys::VARIANTS)->toBe(['thumb', 'preview']);
});

it('keeps staged objects outside the media prefix', function (): void {
    expect(MediaKeys::staging(KEY_ID, 'pdf'))->toStartWith(MediaKeys::STAGING_PREFIX.'/')
        ->and(MediaKeys::staging(KEY_ID, 'pdf'))->not->toStartWith('media/');
});

it('derives the staging key from a stored original key', function (): void {
    expect(MediaKeys::stagingFor(KEY_ID, MediaKeys::original(KEY_ID, 'docx')))->toBe(MediaKeys::staging(KEY_ID, 'docx'));
});

it('builds keys from UUIDs only', function (string $id): void {
    expect(fn () => MediaKeys::original($id, 'png'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => MediaKeys::staging($id, 'png'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => MediaKeys::variant($id, 'thumb'))->toThrow(InvalidArgumentException::class);
})->with([
    'empty' => [''],
    'traversal' => ['../../other-tenant'],
    'upper case' => [strtoupper(KEY_ID)],
    'trailing newline' => [KEY_ID."\n"],
    'path suffix' => [KEY_ID.'/x'],
]);

it('accepts short lower-case extensions only', function (string $extension): void {
    expect(fn () => MediaKeys::original(KEY_ID, $extension))->toThrow(InvalidArgumentException::class)
        ->and(fn () => MediaKeys::staging(KEY_ID, $extension))->toThrow(InvalidArgumentException::class);
})->with(['', 'PNG', 'p/g', '../x', 'toolong', 'php.png', "png\n"]);

it('knows two variants and no others', function (string $name): void {
    expect(fn () => MediaKeys::variant(KEY_ID, $name))->toThrow(InvalidArgumentException::class);
})->with(['original', 'large', '', '../thumb']);
