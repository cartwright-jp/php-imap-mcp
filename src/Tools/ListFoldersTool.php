<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tools;

use Cartwright\ImapMcp\Mail\FolderInfo;
use Cartwright\ImapMcp\Mail\Mailbox;
use Cartwright\ImapMcp\Mail\MailException;
use Cartwright\ImapMcp\Policy\MailPolicy;

/**
 * list_folders (SPEC.md 6): folder names in UTF-8, special use, total and unread counts.
 */
final class ListFoldersTool implements Tool
{
    public function __construct(
        private readonly Mailbox $mailbox,
        private readonly MailPolicy $policy,
    ) {
    }

    public function name(): string
    {
        return 'list_folders';
    }

    public function definition(): array
    {
        $count = ['type' => ['integer', 'null']];

        return [
            'title' => 'List mail folders',
            'description' => 'Lists the mailbox folders with their special use (inbox, sent, drafts, trash, junk, '
                . 'archive), total and unread message counts. Use the names as the folder argument of the other tools.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => new \stdClass(),
                'additionalProperties' => false,
            ],
            'outputSchema' => [
                'type' => 'object',
                'properties' => [
                    'folders' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'special_use' => [
                                    'type' => ['string', 'null'],
                                    'enum' => [...FolderInfo::SPECIAL_USES, null],
                                ],
                                'total' => $count,
                                'unread' => $count,
                                'selectable' => ['type' => 'boolean'],
                                'delimiter' => ['type' => ['string', 'null']],
                            ],
                            'required' => ['name', 'special_use', 'total', 'unread', 'selectable', 'delimiter'],
                        ],
                    ],
                ],
                'required' => ['folders'],
            ],
            'annotations' => MailOutput::readOnlyAnnotations(),
        ];
    }

    public function call(array $arguments): ToolResult
    {
        new Arguments($arguments, []);
        try {
            $folders = $this->mailbox->listFolders();
        } catch (MailException $e) {
            return ToolResult::error($e->getMessage());
        }

        $out = [];
        foreach ($folders as $folder) {
            if (!$this->policy->isFolderAllowed($folder->name)) {
                continue;
            }
            $out[] = [
                'name' => $folder->name,
                'special_use' => $folder->specialUse,
                'total' => $folder->total,
                'unread' => $folder->unread,
                'selectable' => $folder->selectable,
                'delimiter' => $folder->delimiter,
            ];
        }
        $structured = ['folders' => $out];

        return ToolResult::success(MailOutput::text($structured), $structured);
    }

    public function auditArguments(array $arguments): array
    {
        return [];
    }
}
