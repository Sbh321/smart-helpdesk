<?php

declare(strict_types=1);

use App\Modules\Automation\Domain\Text\WordSet;

it('reproduces the word set of the worked example', function (): void {
    $words = WordSet::fromFile()->words('Cannot login after password reset', 'Login page shows error ERR-401 after I reset my password.');

    expect($words)->toBe(['cannot', 'login', 'password', 'reset', 'page', 'shows', 'error', 'err-401']);
});

it('lowercases, splits on punctuation and keeps hyphenated codes', function (): void {
    expect((new WordSet)->words('VPN/Proxy: timeout!!! (code E-503) — user@example.com'))
        ->toBe(['vpn', 'proxy', 'timeout', 'code', 'e-503', 'user', 'example', 'com']);
});

it('drops short words, stop words and hyphen-only tokens', function (): void {
    expect((new WordSet(['The', 'please']))->words('The VM is up -- please see --- it', 'ok'))->toBe(['see']);
});

it('keeps non-English words whole, including combining marks', function (): void {
    expect((new WordSet)->words('प्रिन्टर काम गर्दैन', 'ÉCRAN noir'))->toBe(['प्रिन्टर', 'काम', 'गर्दैन', 'écran', 'noir']);
});

it('returns an empty set for empty text', function (): void {
    expect(WordSet::fromFile()->words('', ''))->toBe([]);
});

it('loads the stop-word file with the words listed on the algorithm page', function (): void {
    $words = WordSet::fromFile();

    foreach (['the', 'and', 'for', 'with', 'after', 'before', 'from', 'this', 'that', 'are', 'was', 'were', 'have', 'has', 'had', 'you', 'your', 'our', 'but', 'not', 'can', 'will', 'please', 'hello', 'thanks', 'regards'] as $stopWord) {
        expect($words->isStopWord($stopWord))->toBeTrue("{$stopWord} should be a stop word");
    }

    expect($words->isStopWord('cannot'))->toBeFalse()
        ->and($words->isStopWord('# Stop words for the Jaccard duplicate baseline (docs/05-algorithms/duplicate-detection.md).'))->toBeFalse()
        ->and($words->isStopWord('THE'))->toBeTrue();
});

it('loads a custom stop-word file and fails clearly on a missing one', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'stop');
    file_put_contents($path, "# comment\n\nlogin\n  Page  \n");

    expect(WordSet::fromFile($path)->words('Login page broken'))->toBe(['broken'])
        ->and(fn () => WordSet::fromFile($path.'-missing'))->toThrow(RuntimeException::class, 'cannot be read');

    unlink($path);
});
