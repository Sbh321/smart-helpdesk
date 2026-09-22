<?php

declare(strict_types=1);

namespace App\Modules\Automation\Domain\Text;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Turns the HTML part of an email into plain text for `ReplyParser` when a message has no text part
 * (docs/04-domain/email.md §ReplyParser). Minimal, like the parser itself (ADR-0023):
 *
 * - `script`, `style`, `head` and `title` are dropped; the rest is read as text, entities decoded;
 * - block elements (`p`, `div`, `li`, `tr`, headings, …) and `br` end a line; runs of white space
 *   inside text collapse to one space;
 * - `blockquote` lines get a `> ` prefix, so the parser's quote rule cuts them like a text reply;
 * - `hr` becomes a line of ten underscores, which is how Outlook separates the quoted message.
 *
 * No links, images, tables or styling survive; the output is only ever an input to the parser.
 */
final class HtmlToText
{
    private const array SKIPPED = ['script', 'style', 'head', 'title', 'template'];

    private const array BLOCKS = [
        'address', 'article', 'aside', 'blockquote', 'dd', 'div', 'dl', 'dt', 'fieldset', 'figure', 'footer',
        'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section',
        'table', 'tr', 'ul',
    ];

    public function convert(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // The XML declaration makes libxml read the markup as UTF-8 instead of ISO-8859-1.
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $text = $this->children($document);
        $lines = array_map(trim(...), explode("\n", $text));

        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    private function children(DOMNode $node): string
    {
        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= $this->node($child);
        }

        return $text;
    }

    private function node(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return (string) preg_replace('/[ \t\r\n\x{00A0}]+/u', ' ', $node->textContent);
        }

        if (! $node instanceof DOMElement) {
            return '';
        }

        $name = strtolower($node->tagName);

        return match (true) {
            in_array($name, self::SKIPPED, true) => '',
            $name === 'br' => "\n",
            $name === 'hr' => "\n__________\n",
            $name === 'blockquote' => "\n".$this->quote($this->children($node))."\n",
            in_array($name, self::BLOCKS, true) => "\n".$this->children($node)."\n",
            default => $this->children($node),
        };
    }

    private function quote(string $text): string
    {
        $lines = explode("\n", trim((string) preg_replace("/\n{3,}/", "\n\n", $text)));

        return implode("\n", array_map(static fn (string $line): string => rtrim('> '.ltrim($line)), $lines));
    }
}
