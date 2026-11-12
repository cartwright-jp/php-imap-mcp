<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Config;

use Cartwright\ImapMcp\Auth\IpRange;
use Cartwright\ImapMcp\Auth\LockoutSettings;
use Cartwright\ImapMcp\Auth\OAuth\OAuthSettings;
use Cartwright\ImapMcp\Policy\MailPolicy;
use Cartwright\ImapMcp\Policy\ToolPolicy;

/**
 * Validated contents of config.php (SPEC.md 8). Build it with ConfigParser or ConfigLoader.
 */
final class Config
{
    /**
     * @param list<IpRange> $allowedIps empty = no restriction (the login page is never restricted)
     * @param list<string> $allowedOrigins exact Origin header values
     * @param list<string> $warnings unsafe but accepted settings, for the error log and bin/check-config.php
     */
    public function __construct(
        /** Sign-in with the mail account over OAuth (auth.oauth, SPEC.md 7). */
        public readonly OAuthSettings $oauth,
        public readonly array $allowedIps,
        public readonly array $allowedOrigins,
        public readonly LockoutSettings $lockout,
        public readonly ToolPolicy $tools,
        public readonly MailPolicy $policy,
        public readonly RuntimeSettings $runtime,
        public readonly array $warnings = [],
    ) {
    }

    public function isIpAllowed(string $ip): bool
    {
        if ($this->allowedIps === []) {
            return true;
        }
        foreach ($this->allowedIps as $range) {
            if ($range->contains($ip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $warnings
     */
    public function withWarnings(array $warnings): self
    {
        return new self(
            $this->oauth,
            $this->allowedIps,
            $this->allowedOrigins,
            $this->lockout,
            $this->tools,
            $this->policy,
            $this->runtime,
            [...$this->warnings, ...$warnings],
        );
    }
}
