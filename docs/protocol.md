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
| 0x02 | HELLO_ACK          | S → C     | `Message\HelloAck`                    | server version, auth method, nonce, capabilities |
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

`COPY_IN`/`COPY_OUT`'s row format is still open — Milestone 19 (Backup,
Dump, Restore) turned out not to need them after all (`Backup\Dumper`/
`Restorer` work embedded, straight through `Schema\Database`, with no
wire traffic at all — see DECISIONS.md), so no milestone currently claims
designing this pair. `SHOW_STATUS`/`SHOW_CONNECTIONS`/`KILL` are settled
as of Milestone 18 — see below.

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

## The server and authentication

`src/Network/Protocol/` is only ever the wire format — it opens no socket
and checks no password. `Network\Server` (Milestone 12) is the real TCP
server built on top of it: a single-process `Network\EventLoop` reactor,
`Network\Acceptor` for the listening socket, and one `Network\Session` per
connection, decoding frames via `Codec` and running `Query` messages
through a real `Execution\Executor`. See `docs/PHASES.md`'s Phase 12
entry and its own DECISIONS.md entries for why an event loop rather than
forking, and why its tests drive `EventLoop::tick()` by hand over a real
socket instead of needing a second process.

`Network\Auth\*` (Milestone 13) is what actually computes and checks the
`Auth`/`AuthOk`/`AuthFail` messages Phase 11 only defined the shape of:

- `HELLO_ACK.nonce` is a fresh, random, per-connection value — not a
  per-user salt (see below for why the field was renamed from PLAN.md's
  own `salt`).
- The client computes `hash = Argon2id(password, SHA-256(username))` and
  sends `Auth { username, response: HMAC-SHA256(nonce, hash) }`.
- The server looks up the same user's stored `hash` (`Network\Auth\UserStore`,
  `users.json`) and recomputes the same `HMAC`, comparing in constant time
  (`Network\Auth\ScramChallenge::verify()`).
- `AuthFail` does **not** close the connection — a client may send a new
  `Auth` to retry — but `Network\Auth\LoginThrottle` locks a username out
  with exponential backoff after enough consecutive failures regardless.

The per-user salt is deliberately not a stored, random value the way
PLAN.md §6.4's `users.json` example shows one: it is derived from the
username alone, which is what lets the four-message handshake PLAN.md §5.4
sketches work at all without an extra round trip to fetch it (at `HELLO_ACK`
time the server does not yet know which user is connecting). See
DECISIONS.md for the full reasoning and its cost.

`bin/minidb user add/remove/list` edits `users.json` directly, without a
server or a client connection — see DECISIONS.md for why it stays a local
file operation rather than a wire request, even now that it lives under
the same `bin/minidb` PLAN.md §9.2 shows.

## Prepared statements

`PREPARE` (a bare SQL string) is answered with `PREPARE_OK` (a `uint32`
statement id) or a `QueryError` if the SQL does not parse. `EXECUTE` names
that id and carries a `WireValue`-encoded parameter list, exactly like
`Query`'s own `$parameters`; the reply is a `QUERY_RESULT` or a `QueryError`,
same as a plain `Query`. `CLOSE_STMT` releases the id and gets no reply at
all — a fire-and-forget message, so closing an id that is unknown or was
already closed is not an error.

Statement ids are scoped to one `Network\Session` and start over at `1` for
every new connection — nothing about them is meaningful across connections,
and `Network\ServerConfig::$maxPreparedStatements` (default `100`, PLAN.md
§7.1) caps how many one connection may hold open at once, past which
`PREPARE` answers with a `QueryError` instead of a new id.

`Network\Session` caches the *parsed* `Sql\Ast\Statement` `PREPARE` produces,
not the raw SQL text: `EXECUTE` hands it straight to
`Execution\Executor::execute()`, which already accepts a pre-parsed
statement, skipping `Sql\Parser::parseOne()` on every run. This is
parse-once, not plan-once — a `SELECT` is still planned and optimized fresh
on every `EXECUTE` — but it is what makes a bound parameter safe: a
parameter is always carried as a typed `WireValue`, decoded into a plain PHP
value and bound by `Execution\Expression\Evaluator`'s existing placeholder
handling, never spliced into SQL text for the parser to see. There is no
code path from a parameter value back into anything the parser interprets as
syntax, which is the actual protection PLAN.md §2.1's "protection against
SQL injection at the protocol level" asks for.

## Transactions

`BEGIN` (optionally naming an `Transaction\IsolationLevel`), `COMMIT`,
`ROLLBACK` and `SAVEPOINT name` are typed wrappers around the same
`Sql\Ast\BeginStatement`/`CommitStatement`/`RollbackStatement`/`SavepointStatement`
classes plain SQL text already produced through `Query` since Phase 8 —
`Network\Session` builds one by hand and hands it to the same
`Execution\Executor::execute()` every other message here already calls.
Each answers with the same empty `QUERY_RESULT` any statement with nothing
to return produces, or a `QueryError` — `BEGIN` while one is already open,
or `COMMIT`/`ROLLBACK`/`SAVEPOINT` with none open, are `TRANSACTION_ERROR`,
not a new wire error code. `ROLLBACK TO SAVEPOINT` and `RELEASE SAVEPOINT`
have no typed message of their own — PLAN.md §5.3's table defines none —
and stay reachable only as plain SQL text through `Query`, same as before
this phase.

`Schema\Database` allows only one open transaction at a time, system-wide
(Phase 8's single-writer model) — a session that opens one and then
disconnects without `COMMIT`/`ROLLBACK` would otherwise leave it open
forever, locking every other session out of `BEGIN` for good. `Network\Session`
tracks whether *it* is the one that currently has a transaction open (by
noticing its own statement flip `Execution\Executor::inTransaction()` from
`false` to `true`, regardless of whether that statement arrived as a typed
message or as `Query` SQL text) and rolls it back automatically on
disconnect if so — a session that never opened one, or already closed it,
does nothing extra on its way out.

## Server administration

`SHOW_STATUS`, `SHOW_CONNECTIONS` and `KILL` (Milestone 18) all answer
with a `QUERY_RESULT` too, like everything else in this protocol that has
something to report — no new message shapes invented for them.

`SHOW_STATUS` replies with one row: `uptime_seconds`, `active_connections`,
`total_connections`, `total_queries`, `total_errors`, `queries_per_second` —
`Network\Metrics`'s cumulative counters (shared across every `Session`,
owned by `Server`) plus `Network\SessionManager::count()` for the one
that is not cumulative. `queries_per_second` is a plain average since the
server started, not a live sliding-window rate.

`SHOW_CONNECTIONS` replies with one row per currently open session: `id`,
`username` (`NULL` until a successful `AUTH`, or always, in dev mode),
`connected_at`, `authenticated`. `KILL <id>` closes that session's socket
and answers with an empty `QUERY_RESULT` — the same "nothing to report"
convention `BEGIN`/`COMMIT` already use — or a `QueryError` naming an
unknown id, the same shape `EXECUTE` of an unknown prepared statement
already uses.

There is no SQL-level `SHOW STATUS;`/`KILL 42;` — PLAN.md §10.4 shows
them as if they were SQL, but `Sql\Lexer`/`Sql\Parser` were never taught
this grammar; a client reaches these only through the typed messages
above. `Client\Connection` exposes `showStatus()`/`showConnections()`/
`kill()` directly, and `Cli\Repl` recognizes the plain text PLAN.md shows
(`Cli\AdminCommand`) and translates it to the same typed calls — see
DECISIONS.md.

## What is deliberately not here yet

- **Chunked result streaming.** See `QUERY_RESULT` above.
- **`COPY_IN`/`COPY_OUT`'s row format** — still nothing needs it designed;
  Milestone 19 (Backup, Dump, Restore) turned out not to be that reason
  after all (see DECISIONS.md).
- **Idle and query timeouts.** `ServerConfig::$idleTimeoutSeconds`/
  `$queryTimeoutSeconds` are recorded but not enforced — `EventLoop` has no
  per-socket elapsed-time tracking yet. A session's automatic rollback on
  disconnect (above) covers whatever a future timeout eventually closes,
  the same as any other disconnect; detecting the timeout itself remains
  unbuilt.
