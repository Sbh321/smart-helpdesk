<?php

declare(strict_types=1);

namespace App\Modules\Automation\Domain\Text;

use RuntimeException;

/**
 * Turns ticket text into a set of meaningful words (docs/05-algorithms/duplicate-detection.md §Step 1):
 * lowercase, replace everything but letters, digits and hyphens with spaces, split, keep words of at
 * least three characters that are not stop words. Hyphenated codes such as `err-401` survive.
 */
final readonly class WordSet
{
    public const int MIN_LENGTH = 3;

    public const string DEFAULT_STOP_WORDS = __DIR__.'/../../../../../resources/automation/stopwords-en.txt';

    /** @var array<string, true> */
    private array $stopWords;

    /**
     * @param  list<string>  $stopWords
     */
    public function __construct(array $stopWords = [])
    {
        $set = [];

        foreach ($stopWords as $word) {
            $set[mb_strtolower(trim($word))] = true;
        }

        $this->stopWords = $set;
    }

    /**
     * Loads a stop-word file: one word per line; blank lines and lines starting with `#` are ignored.
     */
    public static function fromFile(string $path = self::DEFAULT_STOP_WORDS): self
    {
        $lines = is_readable($path) ? file($path, FILE_IGNORE_NEW_LINES) : false;

        if ($lines === false) {
            throw new RuntimeException("Stop-word file {$path} cannot be read.");
        }

        $words = array_filter(array_map(trim(...), $lines), static fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'));

        return new self(array_values($words));
    }

    /**
     * The distinct words of the texts, in order of first appearance.
     *
     * @return list<string>
     */
    public function words(string ...$texts): array
    {
        $text = mb_strtolower(implode(' ', $texts));
        // Letters include combining marks (\p{M}) so that Devanagari words are not split apart.
        $text = (string) preg_replace('/[^\p{L}\p{M}\p{N}-]+/u', ' ', $text);

        $words = [];

        foreach (explode(' ', $text) as $token) {
            if (mb_strlen($token) < self::MIN_LENGTH
                || isset($this->stopWords[$token])
                || trim($token, '-') === '') {
                continue;
            }

            $words[$token] = true;
        }

        return array_map(strval(...), array_keys($words));
    }

    public function isStopWord(string $word): bool
    {
        return isset($this->stopWords[mb_strtolower($word)]);
    }
}
