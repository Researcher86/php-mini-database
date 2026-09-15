<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol;

use PhpMiniDatabase\Exception\ProtocolException;

/**
 * Turns a TCP byte stream — arriving in whatever chunks the socket happens
 * to hand over, one frame split across several `feed()` calls or several
 * frames arriving in one — back into whole `Frame`s.
 *
 * There is no socket here, and none of Milestone 11 needs one: `feed()`
 * takes a plain string, so this is exactly as testable as `Frame` itself.
 * The reading loop a real connection needs (Milestone 12) is `feed()`
 * called with whatever `fread()` returns, then draining `next()` until it
 * returns `null` again.
 */
final class FrameReader
{
    private string $buffer = '';

    /** Appends newly-received bytes to the internal buffer. */
    public function feed(string $bytes): void
    {
        $this->buffer .= $bytes;
    }

    /**
     * The next complete `Frame` buffered so far, or `null` if not enough
     * has arrived yet to know — call again after the next `feed()`.
     * Consumes the frame's bytes from the buffer, so a second call right
     * away returns the *following* frame, not the same one again.
     *
     * @throws ProtocolException when the buffered header is well-formed
     *                           but declares an over-limit payload —
     *                           everything short of a full frame is
     *                           reported as "not yet", not as an error,
     *                           since a genuine TCP stream is always cut
     *                           somewhere
     */
    public function next(): ?Frame
    {
        if (strlen($this->buffer) < Frame::HEADER_SIZE) {
            return null;
        }

        if (substr($this->buffer, 0, 4) !== Frame::MAGIC) {
            throw new ProtocolException('Frame does not start with the "MDB1" magic bytes.');
        }

        $length = unpack('N', $this->buffer, 8)[1];

        if ($length > Frame::MAX_PAYLOAD_SIZE) {
            throw new ProtocolException(sprintf(
                'Frame declares a %d byte payload, over the %d byte limit.',
                $length,
                Frame::MAX_PAYLOAD_SIZE,
            ));
        }

        $frameSize = Frame::HEADER_SIZE + $length;

        if (strlen($this->buffer) < $frameSize) {
            return null;
        }

        $frame = Frame::fromBytes(substr($this->buffer, 0, $frameSize));
        $this->buffer = substr($this->buffer, $frameSize);

        return $frame;
    }
}
