<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol\Message;

use PhpMiniDatabase\Network\Protocol\Message;
use PhpMiniDatabase\Network\Protocol\MessageType;

/**
 * Asks the server for its status. No payload; what the server sends back
 * (a `QueryResultMessage`, most likely — see PLAN.md §10.4's `SHOW STATUS`)
 * is Milestone 18's decision to make, once there is a server with metrics
 * to report.
 */
final readonly class ShowStatus implements Message
{
    public function type(): MessageType
    {
        return MessageType::SHOW_STATUS;
    }

    public function payload(): string
    {
        return '';
    }

    public static function fromPayload(string $bytes): self
    {
        return new self();
    }
}
