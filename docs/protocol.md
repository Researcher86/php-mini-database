# Wire Protocol

This documents the binary protocol implemented under `src/Network/Protocol/`
— PLAN.md §5's design, as it actually exists in code, including the places
this implementation narrows or extends that design. There is no TCP server
yet (Milestone 12): everything here operates on plain byte strings, and is
tested that way.

## Frame

Every message travels inside a fixed-structure frame (`Network\Protocol\Frame`):

```text
+------+------+------+------+------+------+------+------+------+------+------+------+
| magic (4 bytes)           |version|type  |flags |length (4 bytes, uint32)          |
| "MDB1" = 4D 44 42 31       |uint16 |uint8 |uint8 |                                   |
+------+------+------+------+------+------+------+------+------+------+------+------+
| payload (length bytes)                                                              |
+---------------------------------------------------------------------------------------+
```

All multi-byte integers are big-endian, per PLAN.md §5.1. The header is
exactly 12 bytes (`Frame::HEADER_SIZE`); `payload` is exactly `length` bytes.

`Frame::toBytes()` / `Frame::fromBytes()` both work on exactly one complete
frame. `fromBytes()` throws `Exception\ProtocolException` for a bad magic, a
truncated header, a payload shorter or longer than declared, an unknown
message type, or a declared length over `Frame::MAX_PAYLOAD_SIZE` (64 MiB —
the OOM protection PLAN.md §14 names as a risk).

## Reassembling frames from a byte stream

`Network\Protocol\FrameReader` is what a real socket read loop (Milestone 12)
will drive: `feed(string $bytes)` appends whatever a `fread()` call returned,
and `next(): ?Frame` returns the next complete frame once one has fully
arrived, or `null` if not yet. A frame split across many `feed()` calls, or
several frames delivered in one, both work — `next()` is simply called again
until it returns `null`.

```php
$reader = new FrameReader();
$reader->feed($chunkFromSocket);

while (($frame = $reader->next()) !== null) {
    // handle $frame
}
```

## Message types

`Network\Protocol\MessageType` is a single `int`-backed enum covering every
row of PLAN.md §5.3's table. PLAN.md's own file layout names `MessageType`
and `Opcode` as two separate files; this implementation has only one enum —
see [DECISIONS.md](DECISIONS.md#messagetype-and-opcode-are-one-enum-not-two).

| Code | Name               | Direction | Class                                | Payload |
|------|--------------------|-----------|---------------------------------------|---------|
| 0x01 | HELLO              | C → S     | `Message\Hello`                       | version, client name, client version, capabilities |
| 0x02 | HELLO_ACK          | S → C     | `Message\HelloAck`                    | server version, auth method, salt, capabilities |
| 0x03 | AUTH               | C → S     | `Message\Auth`                        | username, challenge response |
| 0x04 | AUTH_OK            | S → C     | `Message\AuthOk`                      | session id, server time |
| 0x05 | AUTH_FAIL          | S → C     | `Message\AuthFail`                    | reason |
| 0x10 | QUERY              | C → S     | `Message\Query`                       | SQL text, bound parameters |
| 0x11 | QUERY_RESULT       | S → C     | `Message\QueryResultMessage`          | column names, rows |
| 0x12 | QUERY_ERROR        | S → C     | `Message\QueryError`                  | error code, message, context |
| 0x13 | PREPARE            | C → S     | `Message\Prepare`                     | SQL text |
| 0x14 | PREPARE_OK         | S → C     | `Message\PrepareOk`                   | statement id |
| 0x15 | EXECUTE            | C → S     | `Message\Execute`                     | statement id, bound parameters |
| 0x16 | CLOSE_STMT         | C → S     | `Message\CloseStatement`              | statement id |
| 0x17 | COPY_IN            | C → S     | `Message\CopyIn`                      | raw bytes (format not yet designed) |
| 0x18 | COPY_OUT           | S → C     | `Message\CopyOut`                     | raw bytes (format not yet designed) |
| 0x20 | BEGIN              | C → S     | `Message\Begin`                       | optional isolation level |
| 0x21 | COMMIT             | C → S     | `Message\Commit`                      | none |
| 0x22 | ROLLBACK           | C → S     | `Message\Rollback`                    | none |
| 0x23 | SAVEPOINT          | C → S     | `Message\Savepoint`                   | name |
| 0x30 | PING               | C → S     | `Message\Ping`                        | none |
| 0x31 | PONG               | S → C     | `Message\Pong`                        | none |
| 0x40 | CANCEL             | C → S     | `Message\Cancel`                      | none |
| 0x50 | GOODBYE            | C ↔ S     | `Message\Goodbye`                     | none |
| 0x60 | SHOW_STATUS        | C → S     | `Message\ShowStatus`                  | none |
| 0x61 | SHOW_CONNECTIONS   | C → S     | `Message\ShowConnections`             | none |
| 0x62 | KILL               | C → S     | `Message\Kill`                        | connection id |

`Network\Protocol\Message` is the interface every one of these implements
(`type(): MessageType`, `payload(): string`); each class also exposes a
static `fromPayload(string $bytes): self`, which is not on the interface
(PHP cannot declare a static factory's return type as "whichever class
implements this" on an interface) — `Codec::decode()` dispatches to the
right one directly, by `MessageType`, the same way `Sql\Parser::statement()`
dispatches on a `TokenType`.

`COPY_IN`/`COPY_OUT`'s row format, and what `SHOW_STATUS`/`SHOW_CONNECTIONS`
actually report, are open: PLAN.md names the messages but not their payload,
and nothing before Milestone 18 (Server Administration) or 19 (Backup,
Dump, Restore) needs them designed. Their frame format is already settled —
`Codec` already round-trips them — only their contents remain to be defined.

## Codec

`Network\Protocol\Codec` is the seam between the two: `encode(Message):
Frame` wraps a message's own `type()`/`payload()` into a `Frame`;
`decode(Frame): Message` dispatches on the frame's `type` to the matching
class's `fromPayload()`.

```php
$codec = new Codec();
$frame = $codec->encode(new Query('SELECT * FROM users WHERE id = ?', [1]));
$bytes = $frame->toBytes();

// ... sent over a socket, reassembled by a FrameReader on the other end ...

$message = $codec->decode($receivedFrame);
// $message instanceof Message\Query
```

## Self-describing values: `WireValue`

A `QUERY`'s bound parameters and a `QUERY_RESULT`'s row values are both
encoded as `Network\Protocol\WireValue` — each value prefixed with a
one-byte `WireValueTag` naming its own shape, rather than relying on a
declared column `Schema\Type` neither one reliably has (a bound parameter's
type is not known until it meets a column; a `SELECT` output column is just
as often a computed value — `price * qty`, `COUNT(*)` — as a stored one).

| Tag | Value | Wire shape |
|-----|-------|------------|
| 0   | `NULL`    | tag byte only |
| 1   | `BOOL`    | 1 byte, `0x00`/`0x01` |
| 2   | `INT`     | 8 bytes, signed, via `Schema\Type\BigIntType`'s own encoding |
| 3   | `FLOAT`   | 8 bytes, IEEE 754 double, big-endian |
| 4   | `STRING`  | `uint32` length + that many bytes |
| 5   | `DATETIME`| 8 bytes, via `Schema\Type\DateTimeType`'s own encoding |

`DECIMAL` and `BLOB` values need no tag of their own: a `DECIMAL` is already
a plain string once it leaves `Schema\Type\DecimalType` (see
[DECISIONS.md](DECISIONS.md#decimal-is-a-scaled-integer-not-a-float)), and a
`BLOB` is already a plain — possibly non-UTF-8 — string; a binary protocol
needs no JSON-style escaping trick for that, unlike `Transaction\Wal`. `DATE`
and `DATETIME` share one tag: both decode to a `DateTimeImmutable`, and
nothing holding a value already in hand needs to know which column type
produced it.

## `QUERY_RESULT`

PLAN.md §5.6 specifies a per-column `type_code`/`flags` pair and a
per-row null bitmap. Neither is produced here: `Execution\QueryResult`
carries only column *labels*, never a `Schema\Type` per column — a
computed column has no single stored type to report even in principle —
so `Message\QueryResultMessage` instead sends:

```text
uint16 column_count
for each column:
  string name
uint64 row_count
for each row, for each column (in that order):
  WireValue                 -- NULL is just another tag, no bitmap needed
```

The `0xFFFFFFFFFFFFFFFF` "still streaming" row count PLAN.md §5.6 allows for
is not produced either: nothing before a real `Network\Session` (Milestone
12) can chunk a result across more than one frame as it is produced, so
`Network\Protocol\ResultEncoder` always reports the true, already-known row
count. See [DECISIONS.md](DECISIONS.md#query_result-drops-the-null-bitmap-and-per-column-type).

`Executor::execute()` returns one of three shapes — a `QueryResult` for
`SELECT`, an `int` row count for `INSERT`/`UPDATE`/`DELETE`, or `null` for
DDL and transaction control — and PLAN.md's message table has only the one
result shape. `ResultEncoder::encode()` represents all three through it: an
`int` becomes one column (`affected_rows`) holding that number, and `null`
becomes zero columns and zero rows. A client tells the three apart by
`count($columns)` alone.

## Errors

`Network\Protocol\ErrorCode` is PLAN.md §5.7/§19's table, plus one addition:
`EXECUTION_ERROR` (`0x0D`), for a syntactically valid statement this engine
cannot run — an ambiguous unqualified column, `GROUP BY` without a `FROM`, a
statement outside any open transaction (`Exception\ExecutionException`) —
which none of PLAN.md's twelve original codes fit.

`ResultEncoder::encodeError()` maps this project's exception hierarchy onto
`ErrorCode`:

| Exception                          | Error code            |
|-------------------------------------|------------------------|
| `Exception\ParserException`         | `PARSER_ERROR`         |
| `Exception\SchemaException`         | `TABLE_NOT_FOUND`      |
| `Exception\TypeException`           | `TYPE_MISMATCH`        |
| `Exception\ConstraintViolationException` | `CONSTRAINT_VIOLATION` |
| `Exception\TransactionException`    | `TRANSACTION_ERROR`    |
| `Exception\StorageException`        | `STORAGE_ERROR`        |
| `Exception\ExecutionException`, anything else | `EXECUTION_ERROR` |

`SchemaException` covers both a missing table and a missing column — this
project's exception hierarchy does not yet distinguish them, so
`TABLE_NOT_FOUND` is the nearer of the two codes rather than a guess parsed
out of the message text. `DEADLOCK`, `AUTH_FAILED`, `PERMISSION_DENIED`,
`QUERY_CANCELLED` and `TIMEOUT` are defined but unmapped: this project has no
deadlock detection, connection-level authentication, permissions,
cancellation, or timeouts to raise them from before a server exists
(Milestones 12–13).

A `Message\QueryError`'s `$context` is a small string-keyed map of
`WireValue`-encoded values — PLAN.md §19's example (`table`, `constraint`,
`value`) made concrete, rather than folded into the message string alone.

## What is deliberately not here yet

- **A socket.** Nothing in `src/Network/Protocol/` opens a connection;
  `FrameReader` is deliberately socket-agnostic so it is exactly as testable
  as everything else here. Milestone 12 is where a real `Network\Server`
  drives it against actual bytes from `fread()`.
- **Authentication logic.** `Hello`/`HelloAck`/`Auth`/`AuthOk`/`AuthFail`
  carry the fields PLAN.md §5.4–5.5 describe; computing or checking a
  challenge response is Milestone 13's job.
- **Chunked result streaming.** See `QUERY_RESULT` above.
- **`COPY_IN`/`COPY_OUT`'s row format**, and **`SHOW_STATUS`/`SHOW_CONNECTIONS`'s
  response shape** — deferred to the milestones that give them a reason to
  exist.
