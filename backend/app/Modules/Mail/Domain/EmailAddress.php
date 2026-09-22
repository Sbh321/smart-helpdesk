<?php

declare(strict_types=1);

namespace App\Modules\Mail\Domain;

/** One mailbox of an address header: the address in lower case and the display name, if any. */
final readonly class EmailAddress
{
    public string $address;

    public function __construct(string $address, public ?string $name = null)
    {
        $this->address = strtolower(trim($address));
    }

    public function domain(): string
    {
        $at = strrpos($this->address, '@');

        return $at === false ? '' : substr($this->address, $at + 1);
    }

    public function localPart(): string
    {
        $at = strrpos($this->address, '@');

        return $at === false ? $this->address : substr($this->address, 0, $at);
    }
}
