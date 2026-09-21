<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Cli;

/** Which of `AdminCommand::parse()`'s three recognized shapes matched. */
enum AdminCommandKind
{
    case SHOW_STATUS;
    case SHOW_CONNECTIONS;
    case KILL;
}
