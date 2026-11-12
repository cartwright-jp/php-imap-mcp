<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Auth;

/**
 * The mail account a request works on, as chosen by the AccountResolver (SPEC.md 4).
 *
 * The password is kept out of var_dump/print_r output; never log or return it.
 */
final class Account
{
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        private readonly string $password,
        public readonly string $imapHost,
        public readonly int $imapPort,
        /** 'ssl' (implicit TLS) or 'starttls'; plaintext connections are not supported (SPEC.md 7). */
        public readonly string $imapEncryption,
        public readonly ?string $fromName = null,
        /** Drafts folder name; null means detect it from the special-use flags. */
        public readonly ?string $draftsFolder = null,
        /** Sent folder name; null means detect it from the special-use flags. */
        public readonly ?string $sentFolder = null,
    ) {
    }

    public function password(): string
    {
        return $this->password;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'password' => '***',
            'imapHost' => $this->imapHost,
            'imapPort' => $this->imapPort,
            'imapEncryption' => $this->imapEncryption,
        ];
    }
}
