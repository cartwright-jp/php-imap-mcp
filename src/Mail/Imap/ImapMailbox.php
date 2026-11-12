<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Mail\Imap;

use Cartwright\ImapMcp\Auth\Account;
use Cartwright\ImapMcp\Mail\AttachmentInfo;
use Cartwright\ImapMcp\Mail\Deadline;
use Cartwright\ImapMcp\Mail\FolderInfo;
use Cartwright\ImapMcp\Mail\Mailbox;
use Cartwright\ImapMcp\Mail\MailException;
use Cartwright\ImapMcp\Mail\MessageDetail;
use Cartwright\ImapMcp\Mail\MessageSummary;
use Cartwright\ImapMcp\Mail\Mime\BodyPart;
use Cartwright\ImapMcp\Mail\Mime\Charset;
use Cartwright\ImapMcp\Mail\Mime\HeaderBlock;
use Cartwright\ImapMcp\Mail\Mime\HtmlToText;
use Cartwright\ImapMcp\Mail\Mime\TransferDecoder;
use Cartwright\ImapMcp\Mail\SearchCriteria;
use Cartwright\ImapMcp\Mail\SearchResult;
use DateTimeImmutable;
use Throwable;
use Webklex\PHPIMAP\Config as WebklexConfig;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\IMAP;

/**
 * Mailbox over IMAP (TLS only, certificate verified). Connects lazily, once per request.
 *
 * webklex/php-imap handles the connection, TLS, LOGIN, LIST, STATUS, EXAMINE/SELECT, SEARCH and STORE.
 * FETCH responses are read with ResponseParser, and only the needed parts are fetched: BODYSTRUCTURE first,
 * then the text parts (partially), so attachment contents are never downloaded (SPEC.md 9).
 */
final class ImapMailbox implements Mailbox
{
    /** Raw bytes fetched per message for a snippet. */
    private const SNIPPET_BYTES = 4096;
    private const SNIPPET_BYTES_HTML = 16384;
    /** Hard cap on the bytes fetched for one body part. */
    private const MAX_PART_BYTES = 4_194_304;
    /** Stop fetching summaries when less than this many seconds remain. */
    private const DEADLINE_MARGIN_SEC = 2.0;
    private const SUMMARY_HEADERS = 'BODY.PEEK[HEADER.FIELDS (DATE FROM TO SUBJECT)]';

    private const SPECIAL_USE_FLAGS = [
        '\\sent' => 'sent',
        '\\drafts' => 'drafts',
        '\\trash' => 'trash',
        '\\junk' => 'junk',
        '\\archive' => 'archive',
        '\\all' => 'all',
        '\\flagged' => 'flagged',
    ];
    /** Fallback when the server does not announce SPECIAL-USE flags (last path component, lowercase). */
    private const SPECIAL_USE_NAMES = [
        'sent' => 'sent', 'sent items' => 'sent', 'sent messages' => 'sent', 'sent mail' => 'sent',
        '送信済み' => 'sent', '送信済みアイテム' => 'sent', '送信済みメール' => 'sent',
        'drafts' => 'drafts', 'draft' => 'drafts', '下書き' => 'drafts',
        'trash' => 'trash', 'deleted items' => 'trash', 'deleted messages' => 'trash', 'ゴミ箱' => 'trash',
        'ごみ箱' => 'trash', '削除済みアイテム' => 'trash',
        'junk' => 'junk', 'spam' => 'junk', 'junk e-mail' => 'junk', '迷惑メール' => 'junk',
        'archive' => 'archive', 'archives' => 'archive', 'アーカイブ' => 'archive',
    ];

    private ?ImapProtocol $imap = null;
    /** @var array{name: string, writable: bool, uidValidity: int}|null */
    private ?array $selected = null;

    public function __construct(
        private readonly Account $account,
        private readonly int $timeoutSec = 10,
        /** Only tests against a self-signed test server turn this off; never configurable (CLAUDE.md). */
        private readonly bool $verifyCertificate = true,
    ) {
    }

    public function checkLogin(): void
    {
        $this->connection();
    }

    public function listFolders(): array
    {
        $imap = $this->connection();
        $list = $this->call(static fn () => $imap->folders('', '*')->validatedData());
        if (!is_array($list)) {
            return [];
        }
        $folders = [];
        foreach ($list as $raw => $info) {
            if (!is_array($info)) {
                continue;
            }
            $raw = (string) $raw;
            $flags = array_values(array_map(
                static fn (mixed $f): string => strtolower(is_string($f) ? $f : ''),
                is_array($info['flags'] ?? null) ? $info['flags'] : [],
            ));
            $delimiter = is_string($info['delimiter'] ?? null) && $info['delimiter'] !== 'NIL'
                ? $info['delimiter']
                : null;
            $name = self::decodeName($raw);
            $selectable = !in_array('\\noselect', $flags, true) && !in_array('\\nonexistent', $flags, true);
            $total = null;
            $unread = null;
            if ($selectable) {
                try {
                    $status = $imap->folderStatus($raw, ['MESSAGES', 'UNSEEN'])->validatedData();
                    if (is_array($status)) {
                        $total = is_int($status['messages'] ?? null) ? $status['messages'] : null;
                        $unread = is_int($status['unseen'] ?? null) ? $status['unseen'] : null;
                    }
                } catch (Throwable) {
                    // Counts are optional; keep listing.
                }
            }
            $folders[] = new FolderInfo(
                $name,
                $delimiter,
                self::specialUse($name, $delimiter, $flags),
                $selectable,
                $total,
                $unread,
            );
        }
        usort($folders, static function (FolderInfo $a, FolderInfo $b): int {
            $inbox = ($b->specialUse === 'inbox') <=> ($a->specialUse === 'inbox');

            return $inbox !== 0 ? $inbox : strcmp($a->name, $b->name);
        });

        return $folders;
    }

    public function search(string $folder, SearchCriteria $criteria): SearchResult
    {
        $uidValidity = $this->open($folder, false);
        $imap = $this->connection();
        $params = [];
        $terms = [
            'FROM' => $criteria->from,
            'TO' => $criteria->to,
            'SUBJECT' => $criteria->subject,
            'TEXT' => $criteria->text,
        ];
        foreach ($terms as $key => $value) {
            if ($value !== null && $value !== '') {
                $params[] = $key;
                // Non-ASCII goes as a literal with CHARSET UTF-8 (quoted strings must be 7-bit).
                $params[] = mb_check_encoding($value, 'ASCII')
                    ? $imap->escapeString($value)
                    : ['{' . strlen($value) . '}', $value];
            }
        }
        if ($criteria->since !== null) {
            array_push($params, 'SINCE', $criteria->since->format('d-M-Y'));
        }
        if ($criteria->before !== null) {
            array_push($params, 'BEFORE', $criteria->before->format('d-M-Y'));
        }
        if ($criteria->unreadOnly) {
            $params[] = 'UNSEEN';
        }
        if ($params === []) {
            $params[] = 'ALL';
        }
        foreach ($params as $p) {
            if (is_array($p)) {
                array_unshift($params, 'CHARSET', 'UTF-8');
                break;
            }
        }

        $ids = $this->call(static fn () => $imap->search($params, IMAP::ST_UID)->validatedData());
        $uids = [];
        foreach (is_array($ids) ? $ids : [] as $id) {
            if ((is_string($id) && ctype_digit($id)) || is_int($id)) {
                $uids[] = (int) $id;
            }
        }
        sort($uids);

        return new SearchResult($uidValidity, array_values(array_unique($uids)));
    }

    public function summaries(string $folder, array $uids, int $snippetChars, Deadline $deadline): array
    {
        if ($uids === []) {
            return [];
        }
        $this->open($folder, false);
        $items = '(UID FLAGS INTERNALDATE RFC822.SIZE BODYSTRUCTURE ' . self::SUMMARY_HEADERS . ')';
        $fetched = $this->fetch(implode(',', $uids), $items);

        $summaries = [];
        foreach ($uids as $uid) {
            $data = $fetched[$uid] ?? null;
            if ($data === null) {
                continue; // expunged meanwhile
            }
            if ($summaries !== [] && $deadline->remaining() < self::DEADLINE_MARGIN_SEC) {
                break;
            }
            $structure = is_array($data['BODYSTRUCTURE'] ?? null)
                ? BodyPart::fromBodyStructure($data['BODYSTRUCTURE'])
                : null;
            $headers = new HeaderBlock(self::bodyItem($data) ?? '');
            $flags = self::flags($data);
            $snippet = '';
            $textParts = $structure?->textParts() ?? [];
            if ($snippetChars > 0 && $textParts !== []) {
                $part = $textParts[0];
                $bytes = $part->subtype === 'html' ? self::SNIPPET_BYTES_HTML : self::SNIPPET_BYTES;
                $text = $this->partText($uid, $part, $bytes);
                $snippet = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, $snippetChars, 'UTF-8');
            }
            $summaries[] = new MessageSummary(
                $uid,
                $headers->date() ?? self::internalDate($data),
                $headers->addresses('From'),
                $headers->addresses('To'),
                $headers->text('Subject'),
                in_array('\\seen', $flags, true),
                in_array('\\flagged', $flags, true),
                ($structure?->attachments() ?? []) !== [],
                is_string($data['RFC822.SIZE'] ?? null) ? (int) $data['RFC822.SIZE'] : 0,
                $snippet,
            );
        }

        return $summaries;
    }

    public function message(string $folder, int $uid, int $maxChars, bool $markSeen, ?int $uidValidity): ?MessageDetail
    {
        $current = $this->open($folder, $markSeen);
        if ($uidValidity !== null && $uidValidity !== $current) {
            throw MailException::uidValidityChanged($folder);
        }
        $data = $this->fetch((string) $uid, '(UID FLAGS INTERNALDATE BODYSTRUCTURE BODY.PEEK[HEADER])')[$uid] ?? null;
        if ($data === null) {
            return null;
        }
        $structure = is_array($data['BODYSTRUCTURE'] ?? null)
            ? BodyPart::fromBodyStructure($data['BODYSTRUCTURE'])
            : new BodyPart('TEXT', 'text', 'plain');
        $headers = new HeaderBlock(self::bodyItem($data) ?? '');

        $texts = [];
        $length = 0;
        $truncated = false;
        $format = MessageDetail::FORMAT_TEXT;
        foreach ($structure->textParts() as $part) {
            if ($length >= $maxChars) {
                $truncated = true;
                break;
            }
            // Generous byte budget: quoted-printable UTF-8 uses 9 bytes per character, HTML adds markup.
            $budget = min(self::MAX_PART_BYTES, ($maxChars - $length) * ($part->subtype === 'html' ? 40 : 12) + 4096);
            $text = $this->partText($uid, $part, $budget, $cut);
            $truncated = $truncated || $cut === true;
            if ($part->subtype === 'html') {
                $format = MessageDetail::FORMAT_HTML;
            }
            if (trim($text) !== '') {
                $texts[] = $text;
                $length += mb_strlen($text, 'UTF-8');
            }
        }
        $body = implode("\n\n", $texts);
        if (mb_strlen($body, 'UTF-8') > $maxChars) {
            $body = mb_substr($body, 0, $maxChars, 'UTF-8');
            $truncated = true;
        }

        $attachments = array_map(
            static fn (BodyPart $p): AttachmentInfo => new AttachmentInfo($p->filename(), $p->mimeType(), $p->size),
            $structure->attachments(),
        );
        $flags = self::flags($data);
        if ($markSeen && !in_array('\\seen', $flags, true)) {
            $imap = $this->connection();
            $this->call(static fn () => $imap->store(['\\Seen'], $uid, null, '+', true, IMAP::ST_UID)->validatedData());
            $flags[] = '\\seen';
        }
        $messageIds = $headers->messageIds('Message-ID');
        $inReplyTo = $headers->messageIds('In-Reply-To');

        return new MessageDetail(
            $uid,
            $current,
            $headers->date() ?? self::internalDate($data),
            $headers->addresses('From'),
            $headers->addresses('To'),
            $headers->addresses('Cc'),
            $headers->addresses('Reply-To'),
            $headers->text('Subject'),
            $messageIds[0] ?? null,
            $inReplyTo[0] ?? null,
            $headers->messageIds('References'),
            in_array('\\seen', $flags, true),
            in_array('\\flagged', $flags, true),
            trim($body),
            $format,
            $truncated,
            $attachments,
        );
    }

    public function appendDraft(string $folder, string $message, string $messageId): ?int
    {
        $this->open($folder, false); // reports a missing folder as such
        $raw = self::encodeName($folder);
        $imap = $this->connection();
        $flags = ['\\Draft', '\\Seen'];
        $lines = $this->call(static fn () => $imap->appendMessage($raw, $message, $flags)->validatedData());
        $this->selected = null; // the folder changed

        // UIDPLUS (RFC 4315): "OK [APPENDUID <uidvalidity> <uid>] ..."
        foreach (is_array($lines) ? $lines : [] as $line) {
            if (is_string($line) && preg_match('/\[APPENDUID \d+ (\d+)\]/i', $line, $m) === 1) {
                return (int) $m[1];
            }
        }
        // Without UIDPLUS, find the draft by its Message-ID.
        $this->open($folder, false);
        $ids = $this->call(static fn () => $imap->search(
            ['HEADER', 'Message-ID', $imap->escapeString('<' . $messageId . '>')],
            IMAP::ST_UID,
        )->validatedData());
        $uids = array_map('intval', array_filter(is_array($ids) ? $ids : [], 'is_numeric'));

        return $uids === [] ? null : max($uids);
    }

    public function close(): void
    {
        if ($this->imap !== null) {
            try {
                $this->imap->logout();
            } catch (Throwable) {
            }
            $this->imap = null;
            $this->selected = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * UTF-8 folder name to the IMAP (modified UTF-7) name.
     */
    public static function encodeName(string $name): string
    {
        if (strcasecmp($name, 'INBOX') === 0) {
            return 'INBOX';
        }

        return mb_convert_encoding($name, 'UTF7-IMAP', 'UTF-8');
    }

    public static function decodeName(string $raw): string
    {
        if (strcasecmp($raw, 'INBOX') === 0) {
            return 'INBOX';
        }
        if (!str_contains($raw, '&')) {
            return Charset::toUtf8($raw, null); // plain ASCII, or raw UTF-8 from UTF8=ACCEPT servers
        }
        $decoded = @mb_convert_encoding($raw, 'UTF-8', 'UTF7-IMAP');

        return $decoded !== '' ? $decoded : $raw;
    }

    private function connection(): ImapProtocol
    {
        if ($this->imap !== null) {
            return $this->imap;
        }
        $imap = new ImapProtocol(WebklexConfig::make([]), $this->verifyCertificate, $this->account->imapEncryption);
        $imap->setConnectionTimeout($this->timeoutSec);
        // stream_socket_client() reports TLS and DNS problems as PHP warnings naming the host; keep them out
        // of the output and report one safe error instead.
        set_error_handler(static fn (): bool => true);
        try {
            $imap->connect($this->account->imapHost, $this->account->imapPort);
        } catch (Throwable $e) {
            error_log(sprintf('php-imap-mcp: IMAP connect failed: %s', $e::class));
            throw MailException::unavailable();
        } finally {
            restore_error_handler();
        }
        try {
            $imap->login($this->account->email, $this->account->password())->validatedData();
        } catch (Throwable $e) {
            error_log(sprintf('php-imap-mcp: IMAP login failed: %s', $e::class));
            $imap->logout();
            throw MailException::loginFailed();
        }

        return $this->imap = $imap;
    }

    /**
     * EXAMINE (read-only) or SELECT the folder; returns its UIDVALIDITY.
     */
    private function open(string $folder, bool $writable): int
    {
        $raw = self::encodeName($folder);
        $current = $this->selected;
        if ($current !== null && $current['name'] === $raw && ($current['writable'] || !$writable)) {
            return $this->selected['uidValidity'];
        }
        $imap = $this->connection();
        try {
            $response = $writable ? $imap->selectFolder($raw) : $imap->examineFolder($raw);
            $result = $response->validatedData();
        } catch (Throwable) {
            $this->selected = null;
            if (!$this->isAlive()) {
                throw MailException::unavailable();
            }
            throw MailException::folderNotFound($folder);
        }
        if (!is_array($result) || !is_int($result['uidvalidity'] ?? null)) {
            throw MailException::folderNotFound($folder);
        }
        $this->selected = ['name' => $raw, 'writable' => $writable, 'uidValidity' => $result['uidvalidity']];

        return $result['uidvalidity'];
    }

    /**
     * UID FETCH, read with ResponseParser.
     *
     * @return array<int, array<string, mixed>> uid => item name => value
     */
    private function fetch(string $uidSet, string $items): array
    {
        $imap = $this->connection();
        $tag = null;
        $this->call(static function () use ($imap, $uidSet, $items, &$tag): void {
            $imap->sendRequest('UID FETCH', [$uidSet, $items], $tag);
        });
        $result = [];
        foreach ($this->readUntilTagged((string) $tag) as $tokens) {
            // * <seq> FETCH (...)
            $isFetch = count($tokens) >= 3 && is_string($tokens[1]) && strtoupper($tokens[1]) === 'FETCH';
            if (!$isFetch || !is_array($tokens[2])) {
                continue;
            }
            $data = ResponseParser::pairs($tokens[2]);
            $uid = $data['UID'] ?? null;
            if (is_string($uid) && ctype_digit($uid)) {
                $result[(int) $uid] = $data;
            }
        }

        return $result;
    }

    /**
     * Decoded UTF-8 text of one part, fetching at most $maxBytes of it.
     *
     * @param-out bool $cut whether the part was longer than $maxBytes
     */
    private function partText(int $uid, BodyPart $part, int $maxBytes, ?bool &$cut = null): string
    {
        $items = sprintf('(UID BODY.PEEK[%s]<0.%d>)', $part->section, $maxBytes);
        $data = $this->fetch((string) $uid, $items)[$uid] ?? [];
        $raw = self::bodyItem($data) ?? '';
        $cut = strlen($raw) >= $maxBytes;
        $decoded = TransferDecoder::decode($raw, $part->encoding, $cut);
        if ($part->subtype === 'html') {
            $charset = $part->charset() ?? HtmlToText::declaredCharset($decoded);

            return HtmlToText::convert(Charset::toUtf8($decoded, $charset));
        }

        return str_replace("\r\n", "\n", Charset::toUtf8($decoded, $part->charset()));
    }

    /**
     * Reads untagged responses until the tagged completion; returns them parsed.
     *
     * @return list<list<mixed>>
     */
    private function readUntilTagged(string $tag): array
    {
        $stream = $this->connection()->getStream();
        if (!is_resource($stream)) {
            throw MailException::unavailable();
        }
        $responses = [];
        while (true) {
            $line = $this->readLine($stream);
            if (str_starts_with($line, $tag . ' ')) {
                $status = strtoupper((string) strtok(substr($line, strlen($tag) + 1), ' '));
                if ($status !== 'OK') {
                    throw MailException::serverError();
                }

                return $responses;
            }
            if (str_starts_with($line, '* ')) {
                $responses[] = ResponseParser::parse(substr($line, 2));
            }
        }
    }

    /**
     * One logical response line, with literals ({n}\r\n + n bytes) inlined.
     *
     * @param resource $stream
     */
    private function readLine($stream): string
    {
        $line = '';
        while (true) {
            $chunk = fgets($stream);
            if ($chunk === false) {
                $this->imap = null;
                $this->selected = null;
                throw MailException::unavailable(); // timeout or closed connection
            }
            $line .= $chunk;
            if (preg_match('/\{(\d+)\+?\}\r\n$/', $chunk, $m) !== 1) {
                return $line;
            }
            $remaining = (int) $m[1];
            while ($remaining > 0) {
                $bytes = fread($stream, min($remaining, 65536));
                if ($bytes === false || $bytes === '') {
                    $this->imap = null;
                    $this->selected = null;
                    throw MailException::unavailable();
                }
                $line .= $bytes;
                $remaining -= strlen($bytes);
            }
        }
    }

    private function isAlive(): bool
    {
        $stream = $this->imap?->getStream();
        if (!is_resource($stream)) {
            return false;
        }
        $meta = stream_get_meta_data($stream);

        return !$meta['timed_out'] && !$meta['eof'];
    }

    /**
     * Runs a webklex call, turning its exceptions into safe MailExceptions (only the class is logged).
     *
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T
     */
    private function call(callable $call): mixed
    {
        try {
            return $call();
        } catch (MailException $e) {
            throw $e;
        } catch (Throwable $e) {
            error_log(sprintf('php-imap-mcp: IMAP command failed: %s', $e::class));
            if (!$this->isAlive()) {
                $this->imap = null;
                $this->selected = null;
                throw MailException::unavailable();
            }
            throw MailException::serverError();
        }
    }

    /**
     * The first BODY[...] item of a FETCH response.
     *
     * @param array<string, mixed> $data
     */
    private static function bodyItem(array $data): ?string
    {
        foreach ($data as $name => $value) {
            if (str_starts_with($name, 'BODY[') && is_string($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string> lowercase flags
     */
    private static function flags(array $data): array
    {
        $flags = $data['FLAGS'] ?? [];

        return is_array($flags)
            ? array_values(array_map(static fn (mixed $f): string => strtolower(is_string($f) ? $f : ''), $flags))
            : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function internalDate(array $data): ?DateTimeImmutable
    {
        $value = $data['INTERNALDATE'] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('d-M-Y H:i:s O', trim($value));

        return $date === false ? null : $date;
    }

    /**
     * @param list<string> $flags lowercase LIST flags
     */
    private static function specialUse(string $name, ?string $delimiter, array $flags): ?string
    {
        if ($name === 'INBOX') {
            return 'inbox';
        }
        foreach ($flags as $flag) {
            if (isset(self::SPECIAL_USE_FLAGS[$flag])) {
                return self::SPECIAL_USE_FLAGS[$flag];
            }
        }
        $last = $delimiter !== null && $delimiter !== '' ? (string) strrchr($delimiter . $name, $delimiter) : $name;
        $last = mb_strtolower(ltrim($last, (string) $delimiter), 'UTF-8');

        return self::SPECIAL_USE_NAMES[$last] ?? null;
    }
}
