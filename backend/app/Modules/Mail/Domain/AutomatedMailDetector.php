<?php

declare(strict_types=1);

namespace App\Modules\Mail\Domain;

/**
 * Recognises mail that no person wrote, so it never becomes a comment or a ticket and never starts a
 * mail loop (docs/04-domain/email.md §Auto-replies and bounces). Minimal and header-only, in the
 * spirit of ADR-0023: the first rule that matches wins, bounces before auto-replies.
 *
 * Bounce: a delivery status report (`multipart/report; report-type=delivery-status`, RFC 3464), an
 * `X-Failed-Recipients` header, the null sender (`Return-Path: <>`) or a sender whose local part is
 * `mailer-daemon` or `postmaster`.
 *
 * Auto-reply: `Auto-Submitted` other than `no` (RFC 3834), `X-Autoreply`, `X-Autorespond` or
 * `X-Auto-Reply` present, or `Precedence: bulk | junk | list | auto_reply`.
 */
final class AutomatedMailDetector
{
    public const string BOUNCE = 'bounce';

    public const string AUTO_REPLY = 'auto_reply';

    private const array DAEMONS = ['mailer-daemon', 'postmaster'];

    private const array PRECEDENCE = ['bulk', 'junk', 'list', 'auto_reply'];

    private const array AUTO_REPLY_HEADERS = ['x-autoreply', 'x-autorespond', 'x-auto-reply'];

    /** `bounce`, `auto_reply` or null for mail a person sent. */
    public function detect(ParsedEmail $email): ?string
    {
        return $this->isBounce($email) ? self::BOUNCE : ($this->isAutoReply($email) ? self::AUTO_REPLY : null);
    }

    private function isBounce(ParsedEmail $email): bool
    {
        $contentType = strtolower((string) $email->firstHeader('content-type'));
        $returnPath = $email->firstHeader('return-path');

        return (str_starts_with($contentType, 'multipart/report') && str_contains($contentType, 'delivery-status'))
            || $email->header('x-failed-recipients') !== []
            || ($returnPath !== null && in_array(trim($returnPath), ['<>', ''], true))
            || ($email->from !== null && in_array($email->from->localPart(), self::DAEMONS, true));
    }

    private function isAutoReply(ParsedEmail $email): bool
    {
        $submitted = strtolower(trim((string) $email->firstHeader('auto-submitted')));
        if ($submitted !== '' && ! str_starts_with($submitted, 'no')) {
            return true;
        }

        foreach (self::AUTO_REPLY_HEADERS as $header) {
            if ($email->header($header) !== []) {
                return true;
            }
        }

        return in_array(strtolower(trim((string) $email->firstHeader('precedence'))), self::PRECEDENCE, true);
    }
}
