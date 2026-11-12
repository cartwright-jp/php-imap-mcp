<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Mail;

final class Address
{
    public function __construct(
        public readonly string $email,
        public readonly string $name = '',
    ) {
    }

    /**
     * 'Name <user@example.com>', or just the address when there is no name.
     */
    public function toString(): string
    {
        if ($this->name === '' || $this->name === $this->email) {
            return $this->email;
        }

        return $this->name . ' <' . $this->email . '>';
    }
}
