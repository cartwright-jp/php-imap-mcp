<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tools;

use InvalidArgumentException;

/**
 * The tool arguments do not satisfy the tool's inputSchema (returned as JSON-RPC -32602).
 */
final class InvalidToolArguments extends InvalidArgumentException
{
}
