<?php

declare(strict_types=1);

namespace Tests\Support;

use phpseclib4\Crypt\RSA;

/**
 * A throwaway RSA key pair for Passport in tests, generated once per machine in the temp
 * directory, so the suite never needs (or overwrites) the keys of the running stack.
 */
final class PassportTestKeys
{
    public static function directory(): string
    {
        $directory = sys_get_temp_dir().'/helpdesk-passport-test-keys';
        $private = $directory.'/oauth-private.key';
        $public = $directory.'/oauth-public.key';

        if (is_file($private) && is_file($public)) {
            return $directory;
        }

        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $key = RSA::createKey(2048);
        file_put_contents($private, (string) $key);
        file_put_contents($public, (string) $key->getPublicKey());
        // league/oauth2-server refuses keys that others can read.
        chmod($private, 0600);
        chmod($public, 0600);

        return $directory;
    }
}
