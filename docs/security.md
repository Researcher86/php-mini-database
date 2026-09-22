# Security Model

What actually protects a running server, as implemented under
`src/Network/Auth/`, `src/Infrastructure/`, and the wire-level guarantees
`docs/protocol.md` already documents in detail — this file is the
consolidated security picture, cross-referencing rather than repeating
that one. It is scoped against PLAN.md §13, and says plainly where this
project stops short of it: PLAN.md itself defers TLS and `GRANT`/`REVOKE`
to "v1.1", which is why neither exists here.

## Authentication

Milestone 13's challenge-response scheme, per PLAN.md §13.1 — see
`docs/protocol.md`'s "The server and authentication" section for the wire
sequence (`HELLO`/`HELLO_ACK`/`AUTH`/`AUTH_OK`/`AUTH_FAIL`). The pieces:

- **Password storage.** `Network\Auth\PasswordHash::derive()` runs
  `sodium_crypto_pwhash()` — Argon2id, `OPSLIMIT_INTERACTIVE`/
  `MEMLIMIT_INTERACTIVE` — and keeps the *raw* 32-byte digest, not a
  `password_hash()`-style encoded string: the challenge-response scheme
  needs those raw bytes as an HMAC key, which `password_verify()` could
  never hand back. See
  [DECISIONS.md](DECISIONS.md#password-hashing-uses-raw-sodium_crypto_pwhash-not-password_hash).
- **Salt.** `PasswordHash::saltFor()` derives a user's salt from
  `SHA-256(username)`, truncated to `SODIUM_CRYPTO_PWHASH_SALTBYTES` —
  not a random value stored in `users.json`. This is a deliberate
  departure from PLAN.md §13.1's "unique salt per user" as commonly
  understood (a stored, random salt); it is still unique *per user*, just
  derived rather than persisted, because the four-message handshake
  PLAN.md §5.4 sketches has no round trip for the server to send a
  per-user salt before it knows which user is connecting. The cost: two
  users who somehow chose the identical password would still get
  different hashes (the salt differs), but the salt itself is guessable
  from the username alone, which a stored random salt would not be. See
  [DECISIONS.md](DECISIONS.md#a-users-salt-is-derived-from-their-username-not-stored)
  for the full trade-off.
- **Replay protection.** `HELLO_ACK.nonce` (`ScramChallenge::nonce()`,
  16 random bytes via `random_bytes()`) is fresh per connection. A
  captured `AUTH` response is an HMAC over that one nonce and is worthless
  against a future connection, which mints its own.
- **Comparison.** `ScramChallenge::verify()` uses `hash_equals()`, not
  `===` — a wrong guess is rejected in constant time rather than short-
  circuiting at the first differing byte, so response timing cannot leak
  how much of a guess was correct.
- **Storage on disk.** `Network\Auth\UserStore` keeps every user in one
  `users.json` (default: `<dataDirectory>/users.json`,
  `ServerConfig::$userStorePath` to override), written through
  `Infrastructure\AtomicWriter` at file mode `0600` — never partially
  written, never world- or group-readable. Only the base64 of the raw
  hash and a user's `roles` are stored; no salt (there is none to store)
  and never the password itself.
- **Rate limiting.** `Network\Auth\LoginThrottle` tracks failures per
  username, in memory, for the server process's lifetime. The first 5
  failures (`$maxAttempts`) are free; the 6th and every one after locks
  the username out with exponential backoff — `$baseDelaySeconds` (1.0s)
  doubled per failure past the limit, capped at `$maxDelaySeconds` (60s).
  `isLocked()` is checked *before* a password is even verified, so a
  locked-out username gets the same generic "too many failed attempts"
  answer whether or not the password this time is right. A successful
  auth (`recordSuccess()`) clears the counter entirely. This is
  per-username, not per-source-address — `Network\Session` has no peer
  address to key by yet — so it stops a single account being brute-forced
  but not a botnet spreading guesses across many usernames from one
  address; a named, narrow gap, not a claim of complete protection (see
  `LoginThrottle`'s own docblock).
- **Dev mode.** `ServerConfig::$authEnabled` defaults to `false`
  (`MINIDB_AUTH_ENABLED=false`) — every connection is accepted with no
  `AUTH` round trip at all. This is the default specifically so every
  test, example, and first `bin/minidb-server start` in this repository
  keeps working without a `users.json` to set up first; running with auth
  disabled anywhere reachable by an untrusted network is the same as
  having no authentication whatsoever, since nothing else in this
  project gates a connection.

## Authorization

There is none, beyond "authenticated or not." `Network\Auth\UserRecord`
carries a `$roles` list (`list<string>`), and `bin/minidb user add
--role <role>` (`Cli\Command\UserCommand`) lets an operator attach
arbitrary role names to a user when creating them — but nothing in
`src/` ever reads `UserRecord::$roles` back. No query, no table, no
`Session` method checks it. PLAN.md §13.2's `admin`/`writer`/`reader`
roles and `GRANT`/`REVOKE` are explicitly scoped to "v1.1" there; `$roles`
existing on the record today is a placeholder for that future work, not
an enforced permission system with a bug in it. Every authenticated user
can run any statement against the one database a server process serves.

## Network

- **Bind address.** `ServerConfig::$host` defaults to `127.0.0.1`,
  matching PLAN.md §13.3 — a server is loopback-only until an operator
  explicitly points `--host`/`MINIDB_HOST` at a routable address.
- **TLS.** Not implemented. Neither `ServerConfig` nor `Client\ClientConfig`
  has a TLS option, `Network\Acceptor` opens a plain `stream_socket_server()`,
  and `Client\Connection` a plain `stream_socket_client()` — every byte
  PLAN.md §5 describes, credentials included (the Argon2id/HMAC scheme
  above protects the *password*, not the query text or result rows that
  follow), crosses the wire unencrypted. PLAN.md §13.3 itself lists TLS as
  "optional: v1.1", so this is a deferred, named gap rather than an
  oversight — but it means a deployment reaching across an untrusted
  network needs a TLS-terminating proxy or tunnel in front of this
  project today, not `ClientConfig`/`ServerConfig` flags that do not
  exist.
- **Frame size limit.** `Network\Protocol\Frame::MAX_PAYLOAD_SIZE` (64 MiB)
  rejects an oversized declared length before any of it is buffered — see
  `docs/protocol.md`'s "Frame" section.
- **Connection limit.** `ServerConfig::$maxConnections` (default 100) is
  enforced by `Network\SessionManager`; `$backlog` (128) is the listen
  backlog `Network\Acceptor` passes straight to `stream_socket_server()`.
- **Idle/query timeouts.** `ServerConfig::$idleTimeoutSeconds`/
  `$queryTimeoutSeconds` are recorded fields with PLAN.md §7.1's own
  defaults, but nothing enforces them — `Network\EventLoop`'s tick-driven
  reactor has no per-socket elapsed-time tracking yet. A stalled or
  malicious connection that never sends a complete frame, or a query that
  runs forever, is not cut off by anything in this project today. See
  `docs/protocol.md`'s "What is deliberately not here yet".

## SQL injection

The actual guarantee is structural, not a sanitizer: a bound parameter
(`Query.parameters`, `Execute.parameters`) is decoded from its
`WireValue` wire encoding straight into a typed PHP value and bound by
`Execution\Expression\Evaluator`'s placeholder handling — there is no
code path from a parameter's *value* back into text the `Sql\Lexer`/
`Sql\Parser` ever tokenizes as syntax. See `docs/protocol.md`'s "Prepared
statements" section for the full path, including why this holds for a
plain `Query` and not only a `PREPARE`d one. PLAN.md §13.4's "literal
escaping in the parser" and "identifier validation" are, in effect, the
same structural guarantee from the other direction: `Sql\Lexer` only ever
recognizes a fixed character class as an identifier
(`isIdentifierStart()`/`isIdentifierPart()`) and a quoted string as a
`'...'`-delimited token with its own escape rule, so there is no way to
smuggle a second statement or a stray identifier through either — not
because a validator rejects it afterward, but because the grammar never
produces one to begin with. Building a query by concatenating untrusted
text directly into SQL (rather than binding it as a parameter) bypasses
all of this the same way it would in any database — nothing in this
project can protect a caller who does that.

## File system

- **Data file permissions.** `Infrastructure\FileSystem` defaults every
  file it writes to `0600` and every directory it creates to `0700` — not
  only `users.json` (above), but the catalog, heap files, indexes, and
  the WAL, matching PLAN.md §13.5.
- **Path traversal.** There is no dedicated validator rejecting a table
  name containing `../` — protection here is again structural:
  `Sql\Lexer`'s identifier grammar (above) is what a `CREATE TABLE`'s
  name must satisfy before it ever reaches `Storage\Catalog`, and that
  grammar does not accept `/` or `.` in an unquoted identifier. A data
  directory path itself (`ServerConfig::$dataDirectory`, `--data`) is
  operator-supplied at server startup, not client-controlled, so it is
  outside this threat model the same way a Postgres `data_directory`
  setting would be.

## Summary of what is *not* covered

Named here together, rather than left implicit: no TLS, no
authorization/permissions beyond "authenticated at all," no per-address
rate limiting (only per-username), no enforced idle/query timeouts, and
no deadlock detection (`LockManager` refuses a conflicting lock outright
instead — see
[DECISIONS.md](DECISIONS.md#lockmanager-never-waits)). Each is either
explicitly deferred to "v1.1" by PLAN.md §13 or documented as unbuilt in
`docs/protocol.md`; none is a silent gap.
