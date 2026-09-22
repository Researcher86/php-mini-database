<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol\Message;

use PhpMiniDatabase\Exception\ProtocolException;
use PhpMiniDatabase\Network\Protocol\Message;
use PhpMiniDatabase\Network\Protocol\MessageType;
use PhpMiniDatabase\Network\Protocol\WireValue;
use PhpMiniDatabase\Support\Binary;

/**
 * A `SELECT`'s answer, entirely materialized — `Execution\QueryResult`'s
 * `$rows` iterable, already consumed, by the time this exists (see
 * `ResultEncoder`). PLAN.md §5.6's per-column `type_code`/`flags` and its
 * row-level null bitmap are both dropped: `Execution\QueryResult` carries
 * only column *labels*, not a `Schema\Type` per column — a computed column
 * (`price * qty`, `COUNT(*)`) has no single stored type to report even in
 * principle — so each row's own `WireValue`-tagged values (already
 * self-describing, `NULL` included) are the only place a value's shape can
 * honestly be known. §5.6's `0xFFFFFFFFFFFFFFFF` "still streaming" row
 * count is also not produced here: nothing before a real `Network\Session`
 * (Milestone 12) can chunk a result across more than one frame as it is
 * produced, so this phase always reports the true, already-known count.
 * See DECISIONS.md.
 */
final readonly class QueryResultMessage implements Message
{
    use WireStrings;

    /**
     * @param list<string>      $columns
     * @param list<list<mixed>> $rows
     */
    public function __construct(
        public array $columns,
        public array $rows,
    ) {
    }

    public function type(): MessageType
    {
        return MessageType::QUERY_RESULT;
    }

    public function payload(): string
    {
        $encoded = pack('n', count($this->columns));

        foreach ($this->columns as $column) {
            $encoded .= self::encodeString($column);
        }

        $encoded .= pack('J', count($this->rows));

        foreach ($this->rows as $row) {
            foreach ($row as $value) {
                $encoded .= WireValue::encode($value);
            }
        }

        return $encoded;
    }

    public static function fromPayload(string $bytes): self
    {
        if (strlen($bytes) < 2) {
            throw new ProtocolException('QUERY_RESULT message is missing its column count.');
        }

        $columnCount = Binary::unpackInt('n', $bytes, 0);
        $offset = 2;
        $columns = [];

        for ($i = 0; $i < $columnCount; $i++) {
            [$column, $offset] = self::decodeString($bytes, $offset);
            $columns[] = $column;
        }

        if (strlen($bytes) < $offset + 8) {
            throw new ProtocolException('QUERY_RESULT message is missing its row count.');
        }

        $rowCount = Binary::unpackInt('J', $bytes, $offset);
        $offset += 8;
        $rows = [];

        for ($r = 0; $r < $rowCount; $r++) {
            $row = [];

            for ($c = 0; $c < $columnCount; $c++) {
                [$value, $offset] = WireValue::decode($bytes, $offset);
                $row[] = $value;
            }

            $rows[] = $row;
        }

        return new self($columns, $rows);
    }
}
