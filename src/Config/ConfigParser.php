<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Config;

use Cartwright\ImapMcp\Auth\Account;
use Cartwright\ImapMcp\Auth\IpRange;
use Cartwright\ImapMcp\Auth\LockoutSettings;
use Cartwright\ImapMcp\Auth\TokenEntry;
use Cartwright\ImapMcp\Policy\MailPolicy;
use Cartwright\ImapMcp\Policy\ToolPolicy;
use DateTimeZone;
use Exception;
use InvalidArgumentException;

/**
 * Validates the array returned by config.php and builds a Config (SPEC.md 8).
 *
 * Every problem is collected so they can be fixed at once. Unknown keys are errors too, because a typo
 * such as 'allowed_ip' would otherwise silently turn a restriction off. Messages name keys, never values.
 */
final class ConfigParser
{
    private const TOP_KEYS = ['accounts', 'auth', 'tools', 'policy', 'runtime'];
    private const ACCOUNT_KEYS = ['email', 'password', 'imap', 'from_name', 'folders'];
    private const IMAP_KEYS = ['host', 'port', 'encryption'];
    /** Implicit TLS or STARTTLS only: plaintext IMAP is not supported (SPEC.md 7). */
    private const ENCRYPTIONS = ['ssl', 'starttls'];
    private const FOLDER_KEYS = ['drafts', 'sent'];
    private const AUTH_KEYS = ['mode', 'tokens', 'allowed_ips', 'allowed_origins', 'lockout'];
    private const TOKEN_KEYS = ['hash', 'account', 'label'];
    private const LOCKOUT_KEYS = ['max_failures', 'window_sec', 'ban_sec'];
    private const POLICY_KEYS = ['allowed_folders', 'search_limit_max', 'body_max_chars', 'deny_move_to_trash'];
    private const RUNTIME_KEYS = ['timezone', 'imap_timeout_sec', 'request_budget_sec', 'storage_dir', 'log_level'];

    /** @var list<string> */
    private array $errors = [];
    /** @var list<string> */
    private array $warnings = [];

    private function __construct(private readonly string $baseDir)
    {
    }

    /**
     * @param string $baseDir directory of config.php; relative paths and the default storage_dir use it
     *
     * @throws ConfigException
     */
    public static function parse(mixed $raw, string $baseDir): Config
    {
        return (new self(rtrim($baseDir, '/\\')))->build($raw);
    }

    private function build(mixed $raw): Config
    {
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw new ConfigException(['config.php must return an array']);
        }
        $this->checkKeys($raw, self::TOP_KEYS, '');

        $accounts = $this->accounts($raw['accounts'] ?? null);
        $auth = $this->section($raw, 'auth');
        $this->checkKeys($auth, self::AUTH_KEYS, 'auth.');
        $mode = $auth['mode'] ?? 'token';
        if ($mode !== 'token') {
            $this->errors[] = "auth.mode must be 'token' (other modes are not supported yet)";
        }
        $tokens = $this->tokens($auth['tokens'] ?? null, $accounts);
        $allowedIps = $this->allowedIps($auth['allowed_ips'] ?? []);
        $allowedOrigins = $this->allowedOrigins($auth['allowed_origins'] ?? []);
        $lockout = $this->lockout($this->section($auth, 'lockout', 'auth.'));
        $tools = $this->tools($this->section($raw, 'tools'));
        $policy = $this->policy($this->section($raw, 'policy'));
        $runtime = $this->runtime($this->section($raw, 'runtime'));

        if ($this->errors !== [] || $tokens === [] || $runtime === null) {
            throw new ConfigException($this->errors);
        }

        return new Config(
            $accounts,
            $tokens,
            $allowedIps,
            $allowedOrigins,
            $lockout,
            $tools,
            $policy,
            $runtime,
            $this->warnings,
        );
    }

    /**
     * @return array<string, Account>
     */
    private function accounts(mixed $raw): array
    {
        if (!is_array($raw) || $raw === [] || array_is_list($raw)) {
            $this->errors[] = 'accounts must be a non-empty array keyed by account id';
            return [];
        }
        $accounts = [];
        foreach ($raw as $id => $entry) {
            $path = sprintf('accounts.%s', $id);
            if (!is_string($id) || preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $id) !== 1) {
                $this->errors[] = 'account ids must be 1-64 characters of A-Z, a-z, 0-9, _, ., -';
                continue;
            }
            if (!is_array($entry)) {
                $this->errors[] = $path . ' must be an array';
                continue;
            }
            $this->checkKeys($entry, self::ACCOUNT_KEYS, $path . '.');
            $email = $entry['email'] ?? null;
            if (!is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $this->errors[] = $path . '.email must be an email address';
            }
            $password = $entry['password'] ?? null;
            if (!is_string($password) || $password === '') {
                $this->errors[] = $path . '.password must be a non-empty string';
            }
            $imap = $this->section($entry, 'imap', $path . '.');
            $this->checkKeys($imap, self::IMAP_KEYS, $path . '.imap.');
            $host = $imap['host'] ?? null;
            if (!is_string($host) || $host === '') {
                $this->errors[] = $path . '.imap.host must be a non-empty string';
            }
            $port = $this->int($imap, 'port', 993, 1, 65535, $path . '.imap.');
            $encryption = $imap['encryption'] ?? 'ssl';
            if (!in_array($encryption, self::ENCRYPTIONS, true)) {
                $this->errors[] = $path . ".imap.encryption must be 'ssl' or 'starttls' (plaintext is not supported)";
            }
            $fromName = $this->optionalString($entry, 'from_name', $path . '.');
            $folders = $this->section($entry, 'folders', $path . '.');
            $this->checkKeys($folders, self::FOLDER_KEYS, $path . '.folders.');
            $drafts = $this->optionalString($folders, 'drafts', $path . '.folders.');
            $sent = $this->optionalString($folders, 'sent', $path . '.folders.');

            if (is_string($email) && is_string($password) && is_string($host) && is_string($encryption)) {
                $accounts[$id] = new Account(
                    $id,
                    $email,
                    $password,
                    $host,
                    $port,
                    $encryption,
                    $fromName,
                    $drafts,
                    $sent,
                );
            }
        }

        return $accounts;
    }

    /**
     * @param array<string, Account> $accounts
     *
     * @return list<TokenEntry>
     */
    private function tokens(mixed $raw, array $accounts): array
    {
        // Without tokens the endpoint would be public: refuse to start (SPEC.md 8).
        if (!is_array($raw) || $raw === [] || !array_is_list($raw)) {
            $this->errors[] = 'auth.tokens must be a non-empty list (create one with bin/generate-token.php)';
            return [];
        }
        $tokens = [];
        $seen = [];
        foreach ($raw as $i => $entry) {
            $path = sprintf('auth.tokens[%d]', $i);
            if (!is_array($entry)) {
                $this->errors[] = $path . ' must be an array';
                continue;
            }
            $this->checkKeys($entry, self::TOKEN_KEYS, $path . '.');
            $hash = $entry['hash'] ?? null;
            $prefix = preg_quote(TokenEntry::HASH_PREFIX, '/');
            if (!is_string($hash) || preg_match('/^' . $prefix . '([0-9a-f]{64})$/', $hash, $m) !== 1) {
                $this->errors[] = $path . ".hash must be 'sha256:' followed by 64 lowercase hex digits"
                    . ' (output of bin/generate-token.php; never put the token itself here)';
                continue;
            }
            if (isset($seen[$m[1]])) {
                $this->errors[] = $path . '.hash duplicates an earlier token';
                continue;
            }
            $seen[$m[1]] = true;
            $account = $entry['account'] ?? null;
            if (!is_string($account) || !isset($accounts[$account])) {
                $this->errors[] = $path . '.account must name an entry in accounts';
                continue;
            }
            $label = $this->optionalString($entry, 'label', $path . '.') ?? '';
            $tokens[] = new TokenEntry($m[1], $account, $label);
        }

        return $tokens;
    }

    /**
     * @return list<IpRange>
     */
    private function allowedIps(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            $this->errors[] = 'auth.allowed_ips must be a list of IP addresses or CIDR ranges';
            return [];
        }
        if ($raw === []) {
            $this->warnings[] = 'auth.allowed_ips is empty: requests are accepted from any IP address';
        }
        $ranges = [];
        foreach ($raw as $i => $notation) {
            try {
                if (!is_string($notation)) {
                    throw new InvalidArgumentException();
                }
                $ranges[] = IpRange::parse($notation);
            } catch (InvalidArgumentException) {
                $this->errors[] = sprintf('auth.allowed_ips[%d] must be an IP address or CIDR range', $i);
            }
        }

        return $ranges;
    }

    /**
     * @return list<string>
     */
    private function allowedOrigins(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            $this->errors[] = 'auth.allowed_origins must be a list';
            return [];
        }
        $origins = [];
        foreach ($raw as $i => $origin) {
            if (!is_string($origin) || preg_match('#^https?://[^/\s?\#]+$#', $origin) !== 1) {
                $this->errors[] = sprintf(
                    "auth.allowed_origins[%d] must be scheme://host[:port] without a path (e.g. 'https://example.com')",
                    $i,
                );
                continue;
            }
            $origins[] = $origin;
        }

        return $origins;
    }

    /**
     * @param array<mixed> $raw
     */
    private function lockout(array $raw): LockoutSettings
    {
        $this->checkKeys($raw, self::LOCKOUT_KEYS, 'auth.lockout.');
        $defaults = new LockoutSettings();

        return new LockoutSettings(
            $this->int($raw, 'max_failures', $defaults->maxFailures, 1, 1000, 'auth.lockout.'),
            $this->int($raw, 'window_sec', $defaults->windowSec, 1, 86400, 'auth.lockout.'),
            $this->int($raw, 'ban_sec', $defaults->banSec, 1, 86400 * 7, 'auth.lockout.'),
        );
    }

    /**
     * @param array<mixed> $raw
     */
    private function tools(array $raw): ToolPolicy
    {
        $this->checkKeys($raw, array_keys(ToolPolicy::DEFAULTS), 'tools.');
        $overrides = [];
        foreach ($raw as $name => $enabled) {
            if (!is_string($name) || !array_key_exists($name, ToolPolicy::DEFAULTS)) {
                continue;
            }
            if (!is_bool($enabled)) {
                $this->errors[] = sprintf('tools.%s must be true or false', $name);
                continue;
            }
            $overrides[$name] = $enabled;
        }

        return new ToolPolicy($overrides);
    }

    /**
     * @param array<mixed> $raw
     */
    private function policy(array $raw): MailPolicy
    {
        $this->checkKeys($raw, self::POLICY_KEYS, 'policy.');
        $defaults = new MailPolicy();
        $folders = $raw['allowed_folders'] ?? [];
        $allowedFolders = [];
        if (!is_array($folders) || !array_is_list($folders)) {
            $this->errors[] = 'policy.allowed_folders must be a list of folder names';
        } else {
            foreach ($folders as $i => $folder) {
                if (!is_string($folder) || $folder === '') {
                    $this->errors[] = sprintf('policy.allowed_folders[%d] must be a non-empty string', $i);
                    continue;
                }
                $allowedFolders[] = $folder;
            }
        }
        $denyTrash = $raw['deny_move_to_trash'] ?? $defaults->denyMoveToTrash;
        if (!is_bool($denyTrash)) {
            $this->errors[] = 'policy.deny_move_to_trash must be true or false';
            $denyTrash = $defaults->denyMoveToTrash;
        }

        return new MailPolicy(
            $allowedFolders,
            $this->int($raw, 'search_limit_max', $defaults->searchLimitMax, 1, 50, 'policy.'),
            $this->int($raw, 'body_max_chars', $defaults->bodyMaxChars, 1, 200000, 'policy.'),
            $denyTrash,
        );
    }

    /**
     * @param array<mixed> $raw
     */
    private function runtime(array $raw): ?RuntimeSettings
    {
        $this->checkKeys($raw, self::RUNTIME_KEYS, 'runtime.');
        $timezone = new DateTimeZone('Asia/Tokyo');
        $tzName = $raw['timezone'] ?? 'Asia/Tokyo';
        try {
            if (!is_string($tzName)) {
                throw new Exception();
            }
            $timezone = new DateTimeZone($tzName);
        } catch (Exception) {
            $this->errors[] = 'runtime.timezone must be a timezone name such as Asia/Tokyo';
        }
        $storage = $raw['storage_dir'] ?? $this->baseDir . '/var';
        if (!is_string($storage) || $storage === '') {
            $this->errors[] = 'runtime.storage_dir must be a non-empty path';
            return null;
        }
        if (!$this->isAbsolute($storage)) {
            $storage = $this->baseDir . '/' . $storage;
        }
        $logLevel = $raw['log_level'] ?? 'info';
        if (!is_string($logLevel) || !in_array($logLevel, RuntimeSettings::LOG_LEVELS, true)) {
            $this->errors[] = 'runtime.log_level must be one of ' . implode(', ', RuntimeSettings::LOG_LEVELS);
            $logLevel = 'info';
        }

        return new RuntimeSettings(
            $timezone,
            $this->int($raw, 'imap_timeout_sec', 10, 1, 60, 'runtime.'),
            $this->int($raw, 'request_budget_sec', 25, 1, 300, 'runtime.'),
            rtrim($storage, '/\\'),
            $logLevel,
        );
    }

    /**
     * @param array<mixed> $parent
     *
     * @return array<mixed>
     */
    private function section(array $parent, string $key, string $prefix = ''): array
    {
        $value = $parent[$key] ?? [];
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->errors[] = $prefix . $key . ' must be an array with named keys';
            return [];
        }

        return $value;
    }

    /**
     * @param array<mixed> $section
     * @param list<string> $known
     */
    private function checkKeys(array $section, array $known, string $prefix): void
    {
        foreach (array_keys($section) as $key) {
            if (!in_array($key, $known, true)) {
                $this->errors[] = sprintf('unknown key %s%s', $prefix, $key);
            }
        }
    }

    /**
     * @param array<mixed> $section
     */
    private function int(array $section, string $key, int $default, int $min, int $max, string $prefix): int
    {
        $value = $section[$key] ?? $default;
        if (!is_int($value) || $value < $min || $value > $max) {
            $this->errors[] = sprintf('%s%s must be an integer from %d to %d', $prefix, $key, $min, $max);
            return $default;
        }

        return $value;
    }

    /**
     * @param array<mixed> $section
     */
    private function optionalString(array $section, string $key, string $prefix): ?string
    {
        $value = $section[$key] ?? null;
        if ($value !== null && (!is_string($value) || $value === '')) {
            $this->errors[] = sprintf('%s%s must be a non-empty string or null', $prefix, $key);
            return null;
        }

        return $value;
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[/\\\\]#', $path) === 1;
    }
}
