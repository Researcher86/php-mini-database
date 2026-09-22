# Decisions

Why this codebase is the way it is: what was tried, what was rejected, and
which trade-offs a design is paying for.

The code says what it does and its comments say why each line is there; this
is the layer above that — the decisions that shaped whole components and the
alternatives that were considered and dropped. It is the least
reconstructible knowledge in the repository, which is why it is written
down.

For the order things were built in, see [PHASES.md](PHASES.md). The plan the
project is built from is [PLAN.md](../PLAN.md).

| Decision | Status |
|---|---|
| Disk and wire share one byte format, owned by `Type` | current, [why](#one-byte-format-for-disk-and-wire) |
| Ordered types encode sign-flipped, so byte order is value order | current, [why](#byte-order-is-value-order) |
| `DECIMAL` is an integer scaled by `10^scale`, surfaced as a fixed-scale string | current, [why](#decimal-is-a-scaled-integer-not-a-float) |
| A type is rebuilt from its *name*; its wire code is only a classification | current, [why](#names-rebuild-a-type-codes-only-classify) |
| Rows live in slotted pages and are addressed by slot, never by offset | current, [why](#slotted-pages-and-addresses-that-survive) |
| A page is held decoded in memory and laid out only on the way to disk | current, [why](#a-page-is-decoded-in-memory) |
| No buffer pool: every read decodes a fresh page | current, [why](#no-buffer-pool-yet) |
| An insert goes into the last page; freed space comes back only at VACUUM | current, [why](#inserts-go-to-the-last-page) |
| The write lock is held on a `.lock` file, not on the data file | current, [why](#the-lock-is-on-its-own-file) |
| `NOT NULL` and `DEFAULT` are column properties, not `Constraint\` classes | current, [why](#not-null-and-default-live-on-the-column) |
| A default is stored exactly as given and cast lazily, never as its canonical form | current, [why](#a-default-is-stored-as-given-not-in-canonical-form) |
| There is no `catalog.json`; the `tables/` directory listing is the catalog | current, [why](#no-catalogjson) |
| `Table` validates itself alone; `Catalog` validates across tables | current, [why](#table-validates-alone-catalog-validates-across-tables) |
| The WAL is logical, and reclaimed only by an explicit checkpoint | current, [why](#the-wal-is-logical-and-reclaimed-only-by-an-explicit-checkpoint) |
| `LockManager` never waits | current, [why](#lockmanager-never-waits) |
| Isolation levels are 2-phase locking, not MVCC | current, [why](#isolation-levels-are-2-phase-locking-not-mvcc) |
| A row is mutated before its WAL record is appended | current, [why](#a-row-is-mutated-before-its-wal-record-is-appended) |
| Undo is logical replay, wired in through a Closure | current, [why](#undo-is-logical-replay-wired-in-through-a-closure) |
| Recovery assumes a single crash | superseded, [why](#recovery-assumes-a-single-crash) — see [Undoing an already-undone change is success](#undoing-an-already-undone-change-is-success-not-an-error) |
| `Database`, not `Executor`, owns transaction and lock state | current, [why](#database-not-executor-owns-transaction-and-lock-state) |
| DDL is not transactional | current, [why](#ddl-is-not-transactional) |
| A `LogicalPlan` node carries its own physical decision; there is no separate physical plan | current, [why](#a-logicalplan-node-carries-its-own-physical-decision) |
| A predicate never pushes past an outer join's nullable side | current, [why](#a-predicate-never-pushes-past-an-outer-joins-nullable-side) |
| Join reordering is a two-table swap by page count, not a search | current, [why](#join-reordering-is-a-two-table-swap-by-page-count-not-a-search) |
| A CHECK constraint's text is reparsed on every write, not cached | current, [why](#a-check-constraints-text-is-reparsed-on-every-write-not-cached) |
| `ConstraintEnforcer` answers; `Executor` acts | current, [why](#constraintenforcer-answers-executor-acts) |
| A cascade cycle is not detected | current, [why](#a-cascade-cycle-is-not-detected) |
| `MessageType` and `Opcode` are one enum, not two | current, [why](#messagetype-and-opcode-are-one-enum-not-two) |
| Wire values are self-describing, not typed by column | current, [why](#wire-values-are-self-describing-not-typed-by-column) |
| `QUERY_RESULT` drops the null bitmap and per-column type | current, [why](#query_result-drops-the-null-bitmap-and-per-column-type) |
| `Frame` and `FrameReader` exist with no socket behind them yet | current, [why](#frame-and-framereader-exist-with-no-socket-behind-them-yet) |
| One process, one event loop — not a fork per connection | current, [why](#one-process-one-event-loop-not-a-fork-per-connection) |
| `Server` tests drive `tick()` by hand over a real socket | current, [why](#server-tests-drive-tick-by-hand-over-a-real-socket) |
| An unhandled message type closes the connection | current, [why](#an-unhandled-message-type-closes-the-connection) |
| A user's salt is derived from their username, not stored | current, [why](#a-users-salt-is-derived-from-their-username-not-stored) |
| Password hashing uses raw `sodium_crypto_pwhash()`, not `password_hash()` | current, [why](#password-hashing-uses-raw-sodium_crypto_pwhash-not-password_hash) |
| Login throttling is per username, in memory, for the process's life | current, [why](#login-throttling-is-per-username-in-memory-for-the-processs-life) |
| User management is its own local CLI script, not a network client command | superseded, [why](#user-management-is-its-own-local-cli-script-not-a-network-client-command) — see [User management stays local, now under bin/minidb itself](#user-management-stays-local-now-under-binminidb-itself) |
| A prepared statement caches the parsed AST, not a plan; it is parse-once, not plan-once | current, [why](#a-prepared-statement-is-parse-once-not-plan-once) |
| PREPARE and CLOSE_STMT failures reuse QueryError; there is no dedicated failure message | current, [why](#prepare-and-close_stmt-failures-reuse-queryerror) |
| A session's disconnect rolls back its own open transaction, judged by watching Executor::inTransaction() flip, not by trusting message type | current, [why](#a-sessions-disconnect-rolls-back-its-own-open-transaction) |
| Typed BEGIN/COMMIT/ROLLBACK/SAVEPOINT are thin wrappers around the same statement classes Query already ran | current, [why](#typed-transaction-messages-wrap-the-same-statement-classes-query-already-ran) |
| Connection uses stream_select for its own read/write timeouts, not stream_set_timeout | current, [why](#connection-uses-stream_select-for-its-own-readwrite-timeouts) |
| ConnectionPool tests a connection on acquire, not on release | current, [why](#connectionpool-tests-a-connection-on-acquire-not-on-release) |
| Client tests run a real bin/minidb-server child process, not Server::tick() driven by hand | current, [why](#client-tests-run-a-real-binminidb-server-child-process) |
| User management stays local, now under bin/minidb itself | current, [why](#user-management-stays-local-now-under-binminidb-itself) |
| import/export are scoped to what the protocol supports: named tables, data only | current, [why](#importexport-are-scoped-to-what-the-protocol-supports) |
| Repl's statement completeness check is tokenize-and-look-at-the-last-token | current, [why](#repls-statement-completeness-check-is-tokenize-and-look-at-the-last-token) |
| Connection::requireOpen() guards against a TypeError stream_select() throws on a closed resource | current, [why](#connectionrequireopen-guards-against-a-typeerror) |
| SHOW_STATUS/SHOW_CONNECTIONS/KILL are typed messages only, never SQL grammar | current, [why](#show_statusshow_connectionskill-are-typed-messages-only) |
| Server::serviceSession() sweeps every session for isClosed(), not only its own | current, [why](#serverservicesession-sweeps-every-session-for-isclosed) |
| bin/minidb-server's PID-file lifecycle never talks to the server over the wire | current, [why](#bin-minidb-servers-pid-file-lifecycle-never-talks-to-the-server) |
| SIGHUP reload is scoped to max_connections only, since nothing else can reload without a config file | current, [why](#sighup-reload-is-scoped-to-max_connections-only) |
| --daemon refuses to run without a real --log-file | current, [why](#daemon-refuses-to-run-without-a-real---log-file) |
| Dumper/Restorer/BackupManager are embedded-only, not network operations | current, [why](#dumperrestorerbackupmanager-are-embedded-only) |
| Dumper orders tables by foreign-key dependency, best-effort | current, [why](#dumper-orders-tables-by-foreign-key-dependency-best-effort) |
| BackupManager uses PharData, not a shelled-out tar binary | current, [why](#backupmanager-uses-phardata-not-a-shelled-out-tar-binary) |
| BackupManager.restore() refuses a non-empty target directory without force | current, [why](#backupmanagerrestore-refuses-a-non-empty-target-directory) |
| SqlSplitter moved from Cli\ to Sql\ once Restorer needed it too | current, [why](#statementsplitter-moved-from-cli-to-sql) |
| Recovery replays a crashed transaction through the same state machine a live one uses | current, [why](#recovery-replays-through-the-same-transaction-state-machine-a-live-rollback-uses) |
| PHPStan went to level 8 by narrowing call sites, not by suppressing them | current, [why](#phpstan-level-8-narrowing-not-suppression) |
| Benchmarks are plain PHPUnit tests in their own testsuite, not a new dependency | current, [why](#benchmarks-are-plain-phpunit-not-a-new-dependency) |
| A transaction belongs to its owning connection, checked by identity, not merely by existing | current, [why](#a-transaction-belongs-to-its-owning-connection) |
| commit()/rollback()/recover() sync storage before their own WAL record, not merely before checkpoint | current, [why](#commitrollbackrecover-sync-storage-before-their-own-wal-record) |
| A failed ROLLBACK stays retryable: the undo log is emptied as soon as it is applied | current, [why](#a-failed-rollback-stays-retryable) |
| Undoing an already-undone change is success, not an error | current, [why](#undoing-an-already-undone-change-is-success-not-an-error) |

## One byte format for disk and wire

A row read from a heap page and a row sent to a client are the same bytes.
`Type::encode()` / `Type::decode()` are the only implementations of either,
and `Storage\RecordSerializer` and `Network\Protocol\ValueCodec` both call
them rather than each carrying its own.

The alternative — a storage encoding tuned for pages and a separate wire
encoding tuned for the protocol — buys the freedom to change one without the
other, and costs a second implementation of every type, a second set of
round-trip tests, and a re-encode on the path from disk to socket, which is
the hot path of a `SELECT`.

The freedom is not worth the cost here: the two formats would want the same
things anyway (compact, self-delimiting, order-preserving). `ValueCodec`
exists precisely so that if the protocol ever does need to diverge, there is
already a seam to widen, and no caller in `Network\` names a `Schema\Type`
directly.

## Byte order is value order

`INT`, `BIGINT`, `DECIMAL`, `DATE` and `DATETIME` are all encoded
big-endian with the sign bit flipped — two's complement XOR the minimum
value. The flip is what makes the encoding *monotonic*: compare two encoded
values as raw byte strings and the answer matches comparing the numbers.
Without it the sign bit puts every negative number above every positive one,
so `-1` would sort after `2147483647`.

This matters for the B-Tree (Phase 6): a node can compare keys with
`strcmp()` on the stored bytes instead of decoding both sides, and a range
scan becomes a walk over a byte-ordered structure. Paying one XOR per value
at write time to get that is a good trade.

Big-endian rather than the machine's native order for the same reason: the
most significant byte has to come first for a prefix comparison to mean
anything.

## `DECIMAL` is a scaled integer, not a float

`DECIMAL(10,2)` stores `123.40` as the int64 `12340` and hands callers back
the string `"123.40"`, never the float `123.4`.

Floats were rejected outright: a column type whose entire purpose is exact
arithmetic cannot be built on a representation where `0.1 + 0.2 !== 0.3`.

The canonical value is a *string* rather than an int because the scale is
part of what the value means — `"123.40"` and `"123.4"` are the same number
but not the same `DECIMAL(10,2)` — and because a caller that receives an int
would have to know the scale to interpret it. Precision is capped at 18
digits so the scaled integer always fits a 64-bit PHP int, which keeps the
arithmetic native.

## Names rebuild a type, codes only classify

`TypeFactory::fromName('VARCHAR(255)')` reconstructs a type exactly.
`TypeFactory::fromCode(3)` returns *a* `VarcharType` that can read `VARCHAR`
bytes, and deliberately refuses `DECIMAL`.

The asymmetry is in the data, not the API. Most of the encodings are
self-describing — an `INT` is four bytes whatever the column declared, a
`VARCHAR` is a length prefix plus that many bytes — so a code is enough to
decode one. `DECIMAL`'s is not: the same eight bytes are `12.34` at scale 2
and `1234` at scale 0, and the code carries no scale.

The factory therefore throws rather than returning a type that would decode
the value wrongly but plausibly. The consequence lands on the protocol: a
column descriptor has to carry the type name, not only its `uint8` code, for
any parametrised type. That is one string per column per result set, paid
once in the header.

## Slotted pages and addresses that survive

A row is addressed as `page:slot` — `Storage\RecordId` — and the slot is an
index into the page's directory, not a byte offset. The indirection is the
whole point: the directory entry can be rewritten when the bytes move, so
compacting a page, or deleting the record next to this one, leaves every
other record findable at the address it already had.

The alternative, addressing a row by its byte offset in the file, is simpler
by exactly one level and wrong the first time anything moves. A B-Tree leaf
holds thousands of these addresses; an `UPDATE` that shortened a row would
otherwise have to find and rewrite every one of them.

Deleting leaves a tombstone — offset 0, length 0, which is unmistakable
because offset 0 is inside the header — rather than removing the directory
entry, for the same reason: removing it would renumber every later slot on
the page. `insert()` reuses a tombstone before appending, so the directory
does not grow without bound under a delete/insert cycle.

## A page is decoded in memory

`Page` holds a list of record strings, one per slot, and builds the 8 KiB
layout only in `toBytes()`. It does not keep the page's bytes around and
edit them in place.

In-place editing is what a database written in C does, and for a good
reason: it can write back just the bytes that changed. This one cannot —
`PageManager` writes whole pages, because a partial page write is not atomic
and recovering from a torn one needs machinery (full-page writes in the WAL)
that is well beyond this project. Given that every write is 8192 bytes
anyway, in-place editing buys nothing and costs the offset arithmetic that
slotted-page bugs are made of.

The memory cost is nil in practice: the decoded records are the same bytes,
minus the free space.

## No buffer pool yet

`PageManager::read()` hits the file and decodes a new `Page` every time.

A buffer pool — keep hot pages in memory, track which are dirty, write them
out on eviction — is the single biggest performance lever in a storage
engine, and it is also three new ways to be wrong: a dirty page lost on
eviction, two callers mutating the same cached `Page` while each believes it
owns it, and a cache that disagrees with the file after a crash.

It is postponed rather than rejected. The seam is this one class: a pool
lives entirely inside `read()`/`write()`/`sync()`, and no caller would
change. The right time to add it is Phase 20, with a benchmark to show what
it bought.

## Inserts go to the last page

`HeapFile::insert()` tries the last page in the file and allocates a new one
if it does not fit. It never looks for the page in the middle of the file
that a `DELETE` left half empty.

The consequence is real: under a delete-heavy workload the file grows and
only `vacuum()` gives the space back. The alternative is a free-space map —
per-page free bytes, consulted on insert, updated on delete — which is a
second on-disk structure that has to stay consistent with the pages
themselves, including across a crash, and it would have to be built and
maintained through every phase that follows.

The trade is: pay a VACUUM now, keep the crash-consistency argument down to
one structure for the transaction phases. `insert()`'s choice of page is the
only thing that would change.

## The lock is on its own file

`FileLock` locks `something.lock`, never the data file it protects.

`flock()` attaches to an open file description, which attaches to an inode.
`AtomicWriter` replaces files by renaming a new inode over the old name — so
a lock taken on the data file would, after any atomic write, be held on an
inode that no longer has a name, while a second process locking the *new*
file would get it immediately. Both processes would believe they held the
write lock.

A lock file is never replaced, only locked, so the inode is stable for as
long as the database exists.

Waiting is a poll of non-blocking `flock()` rather than a blocking one,
because a blocking `flock()` cannot be given a timeout, and a server that
hangs forever on a lock held by a crashed process is worse than one that
reports it could not get the lock.

## `NOT NULL` and `DEFAULT` live on the column

PLAN.md §4's file list puts `NotNull` and `DefaultValue` under
`Constraint\`, alongside `PrimaryKey` and `ForeignKey`. `Schema\Column`
carries them instead, as a `notNull` flag and an optional default.

The catalog's own on-disk format (PLAN.md §6.3) already settled this the
other way: a column in `schema.json` is `{"name": "age", "type": "INT",
"default": null}` — properties of the column, not entries in a separate
constraint list. Two representations of the same fact — a `Constraint\NotNull`
object and a `"not_null"` key on the column that has to be built from it —
would only give a codec bug the chance to make them disagree. Housing it in
one place removes the disagreement rather than resolving it.

It also fits how each is checked. `PrimaryKey`, `UniqueConstraint` and
`ForeignKey` all need something the row does not carry alone (an index or
another table); `NOT NULL` needs nothing but the row itself, at exactly the
place `Table::valuesFromRow()` already fills in defaults, and is enforced
there.

## A default is stored as given, not in canonical form

`Column::rawDefault()` returns whatever `withDefault()` was given — the
literal from a `CREATE TABLE`, or whatever JSON `TableSchemaCodec` decoded —
and `defaultValue()` casts it through the column's `Type` on every call
rather than once, up front.

The alternative — cast once at construction and keep the canonical value —
runs straight into `TableSchemaCodec`: a `DATE` column's canonical default
is a `DateTimeImmutable`, and JSON has no native way to hold one. The codec
would have to turn it back into a string to write `schema.json`, and nothing
in `Type` produces that string — `encode()` makes bytes, not text. Keeping
the raw form sidesteps the question entirely: whatever came out of the JSON
file goes back into it unchanged, and casting on demand is cheap enough
that memoizing it would be optimizing a path nothing calls often.

## No `catalog.json`

PLAN.md §6.1 shows a `catalog.json` at the top of a data directory,
alongside `tables/`. This implementation has no such file: `Storage\Catalog`
lists tables by reading the `tables/` directory itself.

A second file naming the same tables the directory already names is a
second source of truth, and the two have to be kept in step by hand — a
table created but not yet listed, or listed but its directory not yet
removed, is exactly the kind of half-applied state `AtomicWriter` and
`FileLock` exist to prevent elsewhere in this codebase. `scandir()` answers
"what tables exist" correctly by construction; a separate index earns its
existence only by answering something else, like table order or metadata
`schema.json` does not hold, and nothing today needs either.

## `Table` validates alone; `Catalog` validates across tables

`Table`'s constructor checks everything decidable from that one table: no
duplicate or unknown columns, one `PRIMARY KEY` at most and its columns
`NOT NULL`, every constraint and index naming a real column. It does not
check that a `ForeignKey`'s `referencedTable` exists, or that the columns it
points at are actually unique — `Storage\Catalog::createTable()` does, since
only the catalog has every other table loaded to check against.

The split has a sharp edge worth naming: a self-referencing foreign key (a
table whose `ForeignKey` names its own table) has to be validated against
the table object being constructed, not against a stored copy — the table
being created cannot yet be found in a catalog it is not yet part of.
`Catalog::validateForeignKeys()` special-cases exactly this: a reference to
`$table->name` is checked against `$table` itself, everything else against
`$this->table($constraint->referencedTable)`.

The alternative, giving `Table` a reference to the catalog it will live in
so it can validate everything at once, was rejected because it would make
every `Table` depend on where it is stored, which working code that only
constructs one to describe a shape — a test, a migration preview — has no
reason to have.

## One expression tree, not two

PLAN.md's file tree lists `BinaryOp`, `ColumnRef`, `Literal` and
`FunctionCall` under `Execution\Expression\`, as if the executor built its
own expression nodes separately from whatever the parser produces. This
implementation has exactly one expression tree: the parser builds
`Sql\Ast\Expression\*` nodes, and `Execution\Expression\Evaluator`
(Phase 5) will walk those same nodes rather than re-deriving a parallel set
from them.

A second tree would mean a translation step between parsing and execution
— and something to keep in sync as the grammar grows, for no benefit this
project needs: nothing here plans to run the same parsed statement through
more than one back end that would want its own node shapes. PLAN.md §3.4
already commits to "immutable AST and plans"; one tree is what that
principle asks for. If a genuinely execution-only annotation is ever needed
on a node (a resolved column index, a cached type), it is added as a
side-table keyed by node identity, not by forking the tree.

## The parser reuses `ReferentialAction`

`Sql\Ast\TableConstraint\ForeignKeyDefinition::$onDelete` /
`$onUpdate` are typed `Schema\Constraint\ReferentialAction` — the same
enum `Schema\Constraint\ForeignKey` uses — rather than a second enum with
the same four cases (`NO ACTION`, `RESTRICT`, `CASCADE`, `SET NULL`) that
the parser would own.

This is deliberate reuse across what PLAN.md's own layering diagram (§3.2)
already allows: the SQL Interface sits above the Relational Model in the
stack, so it may depend on it. `ReferentialAction` is pure, parameterless
data — there is no parsing or validation step to duplicate the way there
would be for turning `"VARCHAR(255)"` into a `Type` — so a second copy would
be strictly more code expressing the same four words, with a translation
step between the two enums added for nothing.

## A type name is just a string until the executor needs it

`Sql\Ast\ColumnDefinition::$type` holds `"VARCHAR(255)"` exactly as
written. The parser never calls `TypeFactory::fromName()`.

The parser's job ends at "this is syntactically a type name, parenthesized
parameters and all" — it has no way to know yet whether an unparenthesized
`VARCHAR` should be rejected or defaulted, or what an executor building a
`Schema\Table` wants to do with a type name it does not recognise. Resolving
the string is squarely `TypeFactory`'s job (see
["Names rebuild a type, codes only classify"](#names-rebuild-a-type-codes-only-classify)),
called from whatever executes `CREATE TABLE` once that exists (Phase 5).
Keeping the AST inert here means a parser test can assert the exact string
a type was written as without constructing a `Schema\Type` to compare
against.

## Aliases work with or without `AS`

`table u` and `table AS u` parse identically; so do `expr total` and
`expr AS total`. `Parser::optionalAlias()` is the one place this is decided:
`AS` is consumed if present, and otherwise a bare `IDENTIFIER` token is
taken as the alias if one is there.

There is no ambiguity to resolve to make this safe: every keyword that
could start the next clause — `FROM`, `WHERE`, `GROUP`, `JOIN`, a following
`,` — is its own `TokenType`, never `IDENTIFIER`. "The next token is a bare
identifier" already means "it can only be an alias here," with no lookahead
trick required. Given that, requiring `AS` would only be following a
convention some SQL dialects enforce and others don't, for no benefit to a
parser that already disambiguates for free — so both spellings are
accepted uniformly, matching how most real SQL is actually written.

## `DEFAULT` stops before the next modifier keyword

A column's default is parsed with `additiveExpression()` — literals,
arithmetic, unary minus, function calls, parenthesized expressions of any
complexity — not the full `expression()` grammar that also covers
comparisons, `AND`/`OR`, and `NOT`.

The full grammar was tried first and breaks on exactly the case PLAN.md's
own example DDL contains: `age INT DEFAULT 0 CHECK (age >= 0)`. Nothing
separates `0` from the `CHECK` that follows it but whitespace, so a
`DEFAULT` parsed as a full expression walks straight into `comparisonExpression()`'s
"is the next token `NOT`, expecting `IN`/`LIKE`/`BETWEEN`" check — except
here it is a *different* clause's `NOT NULL`, not one of those, and the
parse fails. Since a default was never going to be a comparison or a
boolean combination anyway — that is not what a `DEFAULT` means in SQL — the
fix is also the semantically right scope: stop the grammar one level short
of where the ambiguity starts. A parenthesized default, `DEFAULT (1 + 2)`,
still reaches full `expression()` inside the parens, where the closing
`)` removes any ambiguity.

## Three-valued logic is implemented properly

`Evaluator` treats a `NULL` operand as "unknown," not as `false`: `age > 18`
against a `NULL` age evaluates to PHP `null`, and that `null` then
propagates through further `AND`/`OR`/`NOT` by the standard SQL truth
tables — `FALSE AND NULL` is `FALSE` (the left side alone already settles
it), but `NULL AND NULL` and `TRUE AND NULL` are both `NULL`. Only
`IS [NOT] NULL` is exempt, because answering "is this unknown" is the one
question three-valued logic cannot itself answer with "unknown."

This was not the simpler path — treating `NULL` as `false` throughout would
have been far less code — but it is the *correct* one, and it was worth
getting right from the first phase that evaluates a `WHERE` clause at all.
Approximating it now would mean every test written against the
approximation has to be revisited later, and worse, some genuine bugs
(a `NOT` that silently turns unknown into a wrong `true`) would ship
looking like they work. `Evaluator::isTrue()` is the one place the
three-valued result collapses back to a plain accept/reject decision, for
`WHERE`/`HAVING`.

## `INSERT`/`UPDATE`/`DELETE` stay out of the `Operator` pipeline

`Execution\Operator\*` — `SeqScan`, `Filter`, `Project`, `Sort`, `Limit` —
is `SELECT`'s execution model only. `Executor::executeUpdate()` and
`executeDelete()` call `HeapFile::scan()` directly instead of composing a
`Filter` over a `SeqScan`.

The `Operator` interface is deliberately read-only — every implementation
just yields rows, none of them writes anything back. Mutation does not fit
that shape: `UPDATE` needs the row's `RecordId` to write to, which
`SELECT`'s pipeline has no reason to expose once `Project` has reshaped a
row into the result's own columns, and `UPDATE`/`DELETE` never run through
`Project` at all — WHERE-matching against the *stored* row is what
they need, not a client-facing projection. A shared abstraction here would
have to grow write awareness into every operator to serve two callers that
want different things from it; two direct, honest loops in `Executor` are
plainer than one pipeline pretending to be general enough for both.

## `UPDATE` and `DELETE` collect before they write

Both scan the heap file to find every matching row *before* changing
anything, in a separate pass from applying the change.

The reason is the classic hazard a naive scan-and-mutate loop invites: a
`HeapFile::update()` that grows a row past its page moves it, and
`HeapFile::insert()` — which is what actually places the moved copy — always
appends onto the last page of the file. If that write happened while
`HeapFile::scan()` was still walking pages in order, the moved row could
land on a page the scan had not reached yet and be visited, and updated,
a second time. This is the "Halloween problem," named for the query that
gave every employee a raise until their salary crossed a threshold and,
because the raise moved the row forward in a salary-ordered scan, kept
giving some of them further raises in the same statement.

Reading every match first and writing only after fixes it outright: the
heap file is never mutated while `scan()` is still in progress, so a moved
row cannot reappear in a scan that has already finished. The cost is
holding the matched rows' new bytes in memory for the length of the
statement — bounded by how many rows match, not by the size of the table.
`tests/Unit/Execution/ExecutorTest.php::testGrowingARowDuringUpdateDoesNotVisitItTwice`
is the regression test for exactly this. A more elaborate answer — a
consistent snapshot via MVCC or the WAL, so a scan simply cannot see writes
made after it started — is where Phase 8's transaction work is expected to
eventually subsume this, once there is a WAL to give one.

## `Sort` comes early, `DISTINCT` does not

PLAN.md's Milestone 5 lists `SeqScan`, `Filter`, `Project`, `Limit` as this
phase's operators, with `Sort` and `Aggregate` arriving in Milestone 7
alongside `JOIN` and `GROUP BY`. `Sort` is implemented now anyway;
`DISTINCT`, also named in Milestone 7's checklist, is not.

The two are not equally separable from what Milestone 7 actually adds.
Sorting a materialized set of rows by an expression's value has nothing to
do with joining two tables or computing an aggregate — it is a self-
contained operation this phase already has every piece needed for, and
PLAN.md's own canonical `SELECT` example (§10.3) leads with
`ORDER BY email ASC LIMIT 10`, with no join or aggregate in sight. Leaving
it unexecutable would make "basic SELECT" not actually cover the plan's own
basic example. `DISTINCT`, by contrast, is exactly "group by every selected
column with no aggregate" — the same collapsing-duplicate-rows machinery
`GROUP BY` needs, which is why the plan lists them in the same breath. It
is left refused (`ExecutionException`) until that machinery exists, rather
than special-cased into something that resembles it but is not built on it.

## A dynamic `DEFAULT` is refused, not frozen

`CREATE TABLE t (created_at DATETIME DEFAULT CURRENT_TIMESTAMP)` — the
plan's own example — raises `ExecutionException` in this phase, rather
than succeeding with a default that silently gives every future row without
an explicit value the same timestamp: the moment the table was created.

That would be a genuine correctness bug, not a missing feature: a `DEFAULT`
is supposed to mean "compute this fresh for each row," and
`TableBuilder::defaultValue()` has only one moment — table creation — at
which to evaluate anything at all, since `Schema\Column::withDefault()`
(Phase 3) keeps a single frozen value, not an expression to re-run.
Recomputing it correctly means either `Column` learning to hold an
unevaluated expression alongside (or instead of) a literal, or the executor
keeping a side map from column to its default expression that survives a
catalog reload — either way, a change to how defaults are stored and
reloaded, not a small addition to this phase. Literal defaults (the
overwhelming common case) work fully now; function-call defaults are a
named, tested gap (`TableBuilderTest::testDynamicDefaultIsRejected`)
rather than a silent one, to be picked up when `DEFAULT` handling is
revisited.

## What "basic" execution deliberately does not do

Four things `Executor` refuses outright, each because it needs a piece of
the architecture a later, named milestone builds:

- **`JOIN` and derived tables** (`Sql\Ast\From\Join`, `DerivedTable`) —
  `executeSelect()` accepts only a bare `TableReference` in `FROM`.
  Milestone 7 owns join algorithms; nothing here should grow an ad hoc one.
- **Subqueries** (`ScalarSubquery`, `InSubquery`) — running one means the
  evaluator can run a nested `SELECT`, which means it needs a reference back
  to something that executes statements. That circular shape (`Evaluator`
  calling `Executor` calling `Evaluator`) deserves a deliberate design once
  it is needed, not one improvised to unblock this phase.
- **`GROUP BY`, `HAVING`, `DISTINCT`, and aggregate functions in a select
  list** — Milestone 7's `Aggregate` operator, uniformly, per the reasoning
  above for `DISTINCT`.
- **Enforcing `UNIQUE`, `FOREIGN KEY` and `CHECK` on a write** — `Schema\Table`
  already enforces `NOT NULL`, because it needs nothing beyond the row
  itself; the other three need an index, another table, or an expression
  evaluator running against a stored row, which is Milestone 10's job
  (Integrity Constraints), by design a separate concern from *building* a
  `Schema\Table` that carries these constraints, which `TableBuilder`
  already does today.

Every one of these raises `ExecutionException` with a specific message
rather than executing partially or silently ignoring part of a statement —
a caller finds out immediately that a query needs a later phase, not from a
wrong answer it has to notice on its own.

## A B-Tree node is just a page

`BTreeIndex` defines no page format of its own. An internal or leaf node is
one `Storage\Page` — the identical slotted structure `HeapFile` stores
table rows in — with `PageType::BTREE_INTERNAL`/`BTREE_LEAF` as the only
difference from a heap page, and a one-byte tag inside each record telling
a normal entry from the single reserved slot every node keeps instead of a
key.

The alternative was to give the B-Tree its own fixed-layout node format,
directly manipulating page bytes the way an implementation with a stronger
performance requirement would. `Page` already provides exactly what a node
needs — variable-length slotted records, tombstone-and-reuse deletion,
one write of the whole page — and reusing it means the B-Tree gained a
correct, already-tested page format for free, at the cost of the one-byte
tag each record now needs to disambiguate its two possible shapes. `Page`
itself needed no change at all.

## A stored key is `value + RecordId`, not just the value

Every entry `BTreeIndex` stores is keyed by the indexed value's sortable
bytes *followed by* the row's 8-byte `RecordId` — never the value alone,
even though only one `RecordId` at a time is ever being searched for.

This was not the first design. The first one stored the value alone, and a
stress test inserting many rows under one repeated key found it broken:
`search()` for that key returned only a fraction of the rows that actually
had it. The cause was structural, not a typo. A B+Tree's internal
separators are drawn from real leaf keys, and when a leaf full of
duplicate-valued entries splits, the promoted separator equals the value
itself — meaning descent for that value lands on whichever child the
*most recent* split routed it to, and the earlier leaves holding the same
value, now on the other side of that separator, are never visited again,
because `search()` only chains *forward* through the leaf list from where
it landed. The fraction found kept shrinking as more of the table was
scanned, because the "current" leaf for that value kept moving right one
split at a time — a reproduction script narrowed it to exactly this before
the fix.

Appending the `RecordId` removes the possibility outright: two entries can
never again be byte-identical, so no separator is ever ambiguous, and the
descent that finds "the first leaf that could hold this value" is provably
correct rather than merely usually right. A value-only lookup becomes a
search for the *range* of stored keys carrying that value as their prefix
— `search()` finds the minimum possible full key for the value and reads
forward while the prefix still matches; `range()` builds a low and a high
boundary key the same way. Every other method in the class was already
just comparing whole byte strings with `strcmp()`, so nothing about them
had to change once the strings themselves stopped being ambiguous.

The same fix incidentally erased a second bug that had nothing to do with
correctness: the original leaf-insert path re-decoded, re-sorted and fully
rewrote a page's *entire* entry list on every single insert, which made
filling one page from empty an O(page-size²) operation and one stress test
take 40 seconds. The rewrite is fixed to try `Page::insert()` directly
first now (see the next decision) — a change made at the same time,
because touching this code once to fix correctness was the moment to also
fix what filling a page actually cost.

## A node with room is edited directly, not rebuilt

`insertIntoNode()`'s fast path calls `Page::insert()` on the page it
already read and writes that same page straight back — it does not decode
the node's existing entries, add the new one, and rewrite all of them,
unless the page turns out to be full and a split is actually needed.

A page's slots do not need to be stored in sorted order for this to be
correct: every read (`readLeafEntries()`, `readInternalEntries()`) decodes
and re-sorts regardless of what order the slots happen to be in, so an
insert that just appends a new slot wherever `Page::insert()` puts it
costs nothing extra on the read side later. This is also the fix for the
40-second stress test mentioned in the decision above — the *cost* half of
the same change that fixed the *correctness* half.

## `BTreeIndex::delete()` does not rebalance

Removing an entry drops it from its leaf and rewrites that one page; it
never merges an underflowed leaf with a sibling or shrinks the tree's
height.

This is the identical trade `HeapFile` already made for `DELETE`
(PLAN.md's "no free-space reuse until VACUUM"): rebalancing a B-Tree
correctly — deciding when a node is too empty, finding a sibling to merge
with or borrow from, propagating that change up through parents that may
themselves then underflow — is a meaningfully larger amount of logic than
insertion's splitting, for a benefit (reclaimed page space) `DROP INDEX` +
`CREATE INDEX` already gives back today, the same way `VACUUM` does for a
heap file grown sparse from deletes.

## Only single-column indexes are backed

`Schema\IndexDefinition` already supports naming several columns
(Phase 3), but `Storage\BTreeIndex` indexes exactly one. A composite
`PRIMARY KEY`, `UNIQUE` constraint, or `CREATE INDEX` is recorded in the
schema — `TableBuilder::backingIndexes()` and `Executor::executeCreateIndex()`
both check the column count — but gets no `BTreeIndex` file, no uniqueness
enforcement, and is never chosen by `Executor::selectSource()`.

A composite key's bytes are not simply its columns' bytes concatenated.
`VarcharType`/`BlobType` sort keys have no length prefix (Phase 1's
decision, kept for `BTreeIndex` too — see "Byte order is value order"), so
concatenating a variable-length column with anything after it is ambiguous:
`"AB" + "C"` and `"A" + "BC"` produce the identical bytes from different
original values, in exactly the way a `VARCHAR`'s own sort key already
had to be made unambiguous for a *single* column, except now between
columns instead of within one. Solving that correctly needs either a
length-framed encoding for every non-terminal column or restricting where
a variable-width column may appear in a composite key — real design work
that single-column indexing does not need to block on. The gap is a
`SchemaException`-free but physically absent index, not a silently wrong
one: a composite `UNIQUE` constraint is simply not yet enforced.

## `PRIMARY KEY`/`UNIQUE` gets an automatic index

A single-column `PRIMARY KEY` or `UNIQUE` constraint gets a matching
`IndexDefinition` — same columns, `unique: true`, named after the
constraint — added to the table by `TableBuilder::backingIndexes()`, so it
has a `BTreeIndex` maintained for it exactly like an explicit
`CREATE INDEX ... UNIQUE` would.

This is what "Unique indexes," itemized under this phase in PLAN.md's own
checklist, turns out to mean once there is somewhere to put one: a
`PRIMARY KEY`/`UNIQUE` constraint and a unique index are, mechanically, the
same structure enforcing the same rule. Building the backing index only
when `CREATE INDEX` is written explicitly would leave the far more common
spelling — `id INT PRIMARY KEY`, `email VARCHAR(255) UNIQUE` — silently
unenforced, which is a worse gap than deferring the whole feature would
have been. `FOREIGN KEY` and `CHECK` are not given the same treatment: they
need a different table's data or an expression evaluator running against a
stored row, not just an index, and stay Milestone 10's job as originally
planned.

## Uniqueness is checked before any write

`IndexMaintainer::assertUniqueForInsert()`/`assertUniqueForUpdate()` run
*before* `Executor` writes anything — before the heap insert/update for
`INSERT`, and for `UPDATE`, during the read-only collection pass, before
any row in the statement is touched.

Without a WAL (Phase 8), there is no way to undo a heap write or an
already-updated index once a *later* one in the same operation fails. If
the check ran after writing (the natural order to reach for — write, then
let the unique index itself refuse the duplicate), a rejected `INSERT`
would leave an orphaned row in the heap file with no index entry pointing
to it, and a rejected multi-row `UPDATE` would leave earlier matches in the
same statement already changed while a later one failed. Checking first
means a rejected write never reaches the heap at all, and for `UPDATE`
specifically, collecting every match before writing any of them (already
required to avoid the Halloween problem — see above) means a single
uniqueness violation anywhere in the statement leaves every row untouched,
not just the ones after it.

What this does not fix: a multi-row `INSERT` still applies its rows one at
a time, so a later row's failure does not roll back an earlier row in the
*same* `INSERT` statement that already succeeded — the same documented gap
already noted for `INSERT` before this phase, now also the reason `UPDATE`
was built to check every row before writing any of them, rather than
inheriting the same per-row exposure.

## A rule, not a planner, for `IndexScan`

`Executor::selectSource()` reaches for an `IndexScan` when a `WHERE` clause
— or the first conjunct it finds walking an `AND` chain — is a direct
`column <op> constant` comparison against a column carrying a single-column
index. Anything else (an expression on the column, a comparison the wrong
way round, no matching index) falls back to `SeqScan`.

This is deliberately not Milestone 9's planner arriving early. A real
planner would cost multiple access paths, consider more shapes than one
leading conjunct, and choose among joins once they exist; this rule
recognizes exactly one shape and takes it or does not. What makes the
narrowness safe rather than a correctness risk: `Filter` still runs on the
*complete*, original `WHERE` clause afterward regardless of which source
fed it, including re-checking the very condition `IndexScan` already
narrowed by. A wrong or missed opportunity here only costs a slower scan,
never a wrong answer — which is exactly the property that let this ship
now instead of waiting for Milestone 9, and the property Milestone 9's
version should preserve as it replaces this rule with something that
actually costs its choices.

## `DROP INDEX` deletes the file

`Database::dropIndex()` removes the `.idx` file from disk, not just the
`IndexDefinition` from the table's schema.

Leaving the file behind while only forgetting the schema's reference to it
was tried first, and breaks the moment the same index name is reused: a
`BTreeIndex` decides whether to initialize a fresh tree by checking whether
its file has zero pages, and a stale file left over from before the drop
already has pages full of whatever the dropped index last contained. A
`CREATE INDEX` reusing that name would silently reopen and serve that
old tree — wrong entries, or a `unique` flag from before that no longer
matches what was just declared — rather than building a new one. Deleting
the file is what makes "dropped" and "never existed" the same starting
state for whatever comes next.

## A joined row is qualified before anything else touches it

`Execution\Operator\Qualify` re-keys every column of a table scan as
`"ref.column"` — `id` becomes `"u.id"` — before a join ever sees it, and a
join's own output is already in that same shape, so a chain of joins never
needs to distinguish "a real table's row" from "a nested join's row."
Every downstream piece — `NestedLoopJoin`/`HashJoin` merging two sides,
`QualifiedRowContext` resolving a `ColumnRef` — only ever deals with
already-qualified `Row`s.

The alternative was for a join to carry two rows plus their table names as
a distinct, join-specific value type, and for every operator downstream
(`Filter`, `Sort`, `Project`, `Aggregate`) to learn a second shape of "the
current row" alongside the plain single-table one. Folding the qualifier
into the `Row`'s own keys keeps the *type* every operator already deals in
— a plain `Schema\Row` — the only one that exists anywhere in the
pipeline, joined or not; only the `EvaluationContext` that interprets a
`Row`'s keys differs between the two cases (see the next decision).

## Operators take a context closure, not a fixed table/alias

`Filter`, `Sort` and `Project` construct their `EvaluationContext` by
calling a `Closure(Row): EvaluationContext` the caller supplies, rather
than taking a `$tableName`/`$tableAlias`/`$parameters` triple and building
a `RowContext` internally the way they did through Phase 6.

Phase 7 needed these three operators to work identically over a plain
table's rows (evaluated against `RowContext`) and a joined query's rows
(evaluated against `QualifiedRowContext`) without knowing which kind of
query built them. A closure is the smallest change that gets there: the
three operators stay completely ignorant of which context type exists,
`Executor` decides once per query shape, and adding a third context kind
later — if one is ever needed — touches `Executor`, not any operator.

## `RIGHT JOIN` is a `LEFT JOIN` with its sides swapped

`Executor::buildJoinPipeline()` builds a `RIGHT JOIN a b ON x` by
constructing `NestedLoopJoin($right, $left, isLeftJoin: true, $on, ...)` —
passing the *right* `FromItem` as the operator's first (i.e., "left" in the
operator's own terms) input. `NestedLoopJoin` itself has no `RIGHT` case at
all.

An `ON` expression's truth does not depend on which physical side of the
join a value was read from, only on the values themselves — `u.id =
o.user_id` means the same thing whichever operand position each column
occupies in the evaluated expression. `RIGHT JOIN a b` and `LEFT JOIN b a`
over the same `ON` therefore produce the same *set* of qualified rows (in a
different merge order, which nothing downstream depends on, since every
lookup is by qualified key name, never by position). Writing a third
matching-and-padding branch to distinguish them would be net new code
duplicating the `LEFT` branch's logic with the two inputs' roles reversed;
swapping which `Operator` is passed first costs nothing further.

## `HashJoin` is chosen by a rule, the same as `IndexScan`'s

`Executor::equiJoinKeys()` recognizes exactly one shape: an `INNER JOIN`
whose `ON` is a single top-level equality between one qualified column
from each side. When that check passes, a `HashJoin` replaces the
`NestedLoopJoin` that would otherwise run; nothing else about the query
changes, `Filter` still runs against the complete original `WHERE` and
`ON` had already been fully consumed either way.

This is deliberately the same shape of decision `selectSource()` makes for
`IndexScan` in Phase 6, applied to joins instead of scans: a small, fixed
rule standing in for the cost-based choice Milestone 9's planner will
eventually make, safe to leave narrow because getting it wrong only costs
a slower `NestedLoopJoin`, never a wrong answer — an equi-join condition
`HashJoin` cannot handle (a non-equality, an `OR`, a comparison against a
constant instead of the other side) is refused by `equiJoinKeys()`
returning `null`, not attempted incorrectly.

## Aggregates are substituted, then evaluated normally

`Execution\Operator\Aggregate::substituteAggregates()` walks a select-list
or `HAVING` expression and replaces every aggregate function call it finds
— wherever it is nested, not only at the top — with a `Literal` holding
that aggregate computed over the current group's rows. The *rewritten*
expression, which now contains no aggregate calls at all, is handed to the
ordinary `Evaluator` exactly as any other expression would be.

The alternative was to give `Evaluator` itself the concept of "the current
group of rows" and teach it to recognize `COUNT`/`SUM`/`AVG`/`MIN`/`MAX`
as needing many rows instead of one. That would have made `Evaluator`
usable only where a group happens to be in scope, and every one of its
other callers (a `WHERE` clause, a column default, an `INSERT` value) would
have to either supply a meaningless single-row "group" or be special-cased
around. Substitution keeps `Evaluator` knowing nothing about aggregation at
all — the one thing it evaluates is a plain expression tree, always — and
confines everything aggregate-specific to the one operator whose entire
purpose is aggregation. It is also what makes `HAVING COUNT(*) > 1` work
for free: `substituteAggregates()` recurses through the comparison and
only the `COUNT(*)` inside it is replaced, so the comparison itself is
just an ordinary `BinaryOp` by the time `Evaluator` sees it.

## `ORDER BY` moves depending on what it needs to see

For a plain (non-aggregate) query, `Sort` runs *before* `Project`, against
the same row `Filter` already saw — so `SELECT name FROM users ORDER BY
age` can sort by a column the select list never mentions. For a grouped
query, `Sort` runs *after* `Aggregate`, against the aggregate's own output
row — so `ORDER BY total DESC` can reference a `SUM(...) AS total` alias
that only exists once every group has been collapsed to one row.

Both placements are necessary, not a stylistic choice: swapping them would
break one case to fix the other. A plain query's `ORDER BY` column is
routinely absent from the select list, so it has to be evaluated against
the *source* row, before projection has discarded anything. A grouped
query's `ORDER BY` expression, by contrast, is typically the aggregate
result itself or its alias — something that exists nowhere in the source
rows individually and is only computable per group, so it has to run
*after* `Aggregate` has produced it. `finishSelect()` picks the placement
per query, based on whether the query is aggregating at all — the one
piece of information that decides which row shape `ORDER BY` actually has
in front of it.

The consequence, left as a named simplification rather than solved: a
grouped query's `ORDER BY` can reference a `GROUP BY` key or a select-list
alias (both present on the output row `Sort` sees), but not repeat a bare
aggregate expression the select list already re-derives under a different
name (`ORDER BY COUNT(*)` without an alias) — `Evaluator` has no
aggregate-substitution step of its own outside `Aggregate`, and this
`Sort` runs after `Aggregate`, not through it. Writing `ORDER BY` against
an aliased column, the normal way to do this, is unaffected.

## The WAL is logical, and reclaimed only by an explicit checkpoint

`Transaction\Wal` records "row X in table Y changed from this to that,"
not which bytes of which page moved. PLAN.md's own layout sketches
numbered log segments (`wal.0001.log`, `wal.0002.log`); this
implementation keeps one file instead, and only ever shrinks it by
truncating it outright, once `TransactionManager` confirms nothing still
depends on it (`checkpoint()`, called after a commit, a rollback, and
after recovery).

A physical log — page images or byte ranges — would let recovery replay
raw writes without touching `Schema\Table` or `Execution\IndexMaintainer`
at all, and would compose naturally with rotation, since a segment can be
discarded once every page it touches is known durable on its own. Neither
of those benefits is worth what it costs here: this engine already has a
`Table`/`HeapFile`/`IndexMaintainer` path that knows how to apply one
logical change safely (uniqueness checked first, indexes kept in step),
and a physical log would have to duplicate that knowledge rather than
reuse it. Log rotation solves an unbounded-growth problem this project
does not have yet — a WAL here lives only from one `BEGIN` to the next
`COMMIT`/`ROLLBACK` (checkpointed immediately after), never accumulating
across many transactions the way a server handling continuous traffic
would.

This is the same trade every other piece of on-disk state in this project
already makes: `HeapFile` reclaims space only at an explicit `VACUUM`,
`BTreeIndex` never rebalances at all. A named, bounded gap once a real
workload shows it matters is preferred here over building for a shape of
growth this project does not yet have.

Two properties the log needs for that truncation to be safe, both added
once a reviewer asked what happens if a crash lands *inside* the
checkpoint. The truncation is `fsync()`'d, like every append: until it
reaches the device the records it dropped are still there, and the next
`append()` writes over them from offset 0 — leaving, if a crash lands in
between, new records followed by the tail of older ones, every line of
which parses, so nothing would notice. And `readAll()` treats a
malformed *last* line as the end of the log rather than a failure: the
file is append-only and every complete record is `fsync()`'d, so the only
way to produce one is a write a crash cut in half, and a record that
never finished being written is correctly not part of the log. A
malformed line anywhere else is real corruption and still throws. Before
that, a torn final write left a WAL `Wal::open()` could not read — and
so a database that could never be opened again, the same failure class
as the undo bugs above.

## `LockManager` never waits

`LockManager::acquire()` either grants a lock immediately or throws
`TransactionException` — there is no queue, no timeout, no blocking. This
is a real departure from `Infrastructure\FileLock`'s poll-with-timeout
model (Phase 2), which exists for exactly the situation `LockManager`
cannot be in: two genuinely separate OS processes, where waiting a little
might let the other one finish and release.

`LockManager` instead arbitrates locks between `Transaction`s inside one
`TransactionManager`, and — since only one transaction can ever be
current at a time (see below) — a lock conflict here can only happen
between the current transaction and one that is *only reachable through
data left behind on the WAL*, or, once Phase 12 gives this engine a real
server, between two genuinely concurrent sessions in the same process.
Either way, making the current call block would freeze the only thread
that could ever release the lock it is waiting on. Refusing outright and
letting the caller decide what to do (retry the whole statement, surface
the error) is the only choice that cannot deadlock the process against
itself.

## Isolation levels are 2-phase locking, not MVCC

`READ_COMMITTED`, `REPEATABLE_READ` and `SERIALIZABLE` are implemented by
which locks a statement takes and how long it holds them
(`Executor::lockForRead()`, and the exclusive locks every write already
takes), not by keeping multiple versions of a row and picking one per
transaction's snapshot.

MVCC is what most production databases actually use, and it has a real
advantage this project is giving up: a reader never blocks a writer, or
vice versa. It also needs machinery this engine does not have and was not
about to grow just for this — a buffer pool holding several live versions
of a page, a way to garbage-collect versions no open transaction can still
see, and a notion of transaction snapshot ordering. Two-phase locking
needs none of that: it reuses the heap file and indexes exactly as every
earlier phase already built them, at the cost of a reader and a writer
sometimes blocking each other under `REPEATABLE_READ`/`SERIALIZABLE` where
MVCC would not have to.

Read-locking is deliberately narrow in two more ways. First, it only
triggers for an explicit, still-open transaction — an autocommit `SELECT`
has no *later* statement in the same transaction for a repeatable read to
protect, so it takes no lock at all regardless of isolation level.
Second, it only covers single-table statements: a `JOIN`'s rows lose their
per-table `RecordId` once `Qualify`/`NestedLoopJoin`/`HashJoin` merge them
into one qualified row, so there is no address left to attach a lock to.
Both are named gaps, not silent ones — extending either needs work this
phase did not need to do to prove the isolation levels' basic mechanics.

## A row is mutated before its WAL record is appended

`Executor::executeInsert()` (and `Update`/`Delete`) writes to the heap
file and its indexes *first*, and only calls
`TransactionManager::logInsert()`/`logUpdate()`/`logDelete()` — which
appends and `fsync()`s — after. Textbook write-ahead logging says the
opposite: the log record exists before the change it describes.

The reason is `RecordId`: an `INSERT`'s new one, and a moving `UPDATE`'s
post-move one, are only known once `HeapFile::insert()`/`update()` has
actually run — there is nothing to put in the WAL record beforehand
except a placeholder, and a placeholder recovery could not use to find
the row it needs to undo defeats the log's purpose. What "write-ahead"
actually has to guarantee — that a transaction's complete log is durable
before its `COMMIT` marker is — still holds under this ordering, since
every mutation's record is appended and fsync'd before the statement that
made it returns, which is always before the `COMMIT` that ends the
transaction. What is given up is narrower and named: a crash in the exact
window between the heap mutation and its WAL append is not recoverable —
a small, acknowledged gap rather than an unexamined one.

## Undo is logical replay, wired in through a Closure

Reversing a `WalRecord` (`Executor::undo()` and its `undoInsert`/
`undoUpdate`/`undoDelete`) runs it back through the same
`Schema\Table`/`Storage\HeapFile`/`Execution\IndexMaintainer` path a fresh
`INSERT`/`UPDATE`/`DELETE` would use — deleting an inserted row, reinserting
a deleted one, restoring an updated one's old values — rather than
restoring raw page bytes, which follows directly from the WAL itself being
logical (see above): there is no physical image to restore from.

This creates a real circular dependency: `TransactionManager` needs to be
able to undo a change to run `rollback()`/`recover()`, but *how* to undo
one is knowledge only `Executor` has. Neither class should depend on the
other's concrete type — `TransactionManager` is meant to be usable by
whatever future caller does row mutation (this engine only has one today),
and `Executor` already depends on `TransactionManager` to dispatch `BEGIN`/
`COMMIT`/etc. `TransactionManager::setUndoHandler(Closure $handler)` is
the seam: `Executor`'s constructor passes `$this->undo(...)`, a first-class
callable reference to its own private method, after both objects already
exist. `TransactionManager` calls the closure without ever knowing what is
on the other side of it — an interface (`Undoer::undo(WalRecord): void`)
would express the same contract with a named type instead of a closure's
implicit one, but would not remove the wiring step this callback already
does in one line, and this project already reaches for a `Closure` for the
same reason elsewhere (`Execution\Operator`'s `Closure(Row):
EvaluationContext` context factories, Phase 7).

## Recovery assumes a single crash

`TransactionManager::recover()` undoes every transaction the WAL shows a
`BEGIN` for but no matching `COMMIT`/`ROLLBACK`, then checkpoints the log.
It does not defend against a second crash happening *during* that undo
pass — if the process dies again partway through, the next `recover()`
call has no record of which of the first pass's undos already completed.

A fully crash-safe recovery would make each undo step itself
crash-recoverable — logging its own progress, or making every undo
operation idempotent so replaying one twice is harmless. That is real
complexity for a failure mode two full crashes in immediate succession
that this project's test harness cannot even reliably reproduce, let alone
one a learning project's own use ever exercises. The single-failure
assumption is stated here rather than left for a future reader to
discover by tracing what `recover()` does not check.

**Superseded.** The second of those two options turned out to be the
cheap one, and a later review found the assumption was not merely
untested but actively harmful: a failed `sync()` followed by a crash
left a database that could never be opened again, since `recover()` runs
in `Executor`'s constructor and threw on rows a previous pass had
already undone. Undo is idempotent now, heap and index alike — see
"[Undoing an already-undone change is success, not an error](#undoing-an-already-undone-change-is-success-not-an-error)"
— so a crash *inside* an undo pass, recovery's own included, leaves work
the next pass simply finishes. What is still assumed is narrower and
lower down: that a page write either happened or did not. This engine
has no double-write buffer and logs no full-page images, so a power loss
that tears a single 8 KiB page mid-write is outside what any of the
above can repair.

## `Database`, not `Executor`, owns transaction and lock state

`Schema\Database::wal()`, `locks()` and `transactions()` construct and
cache a `Wal`, a `LockManager` and a `TransactionManager`, the same way
`heapFile()`/`index()` already cache open file handles — and
`Execution\Executor`'s constructor asks `Database` for all three rather
than building its own. A transaction, and the locks it holds, are
properties of a *connection* to the database, not of one particular
`Executor` object built to talk to it: two `Executor`s wrapping the same
`Database` need to see the same currently-open transaction (and have a
second `BEGIN` from either one refused while it is open), the way two
statements sent down one real database connection would — and, since both
would otherwise point at the very same on-disk WAL file, two independent
`TransactionManager`s watching it would each misread the other's
in-progress transaction as an abandoned one to recover.

That last point is also why `TransactionManager::recover()` is a no-op
after its first successful call: `Executor`'s constructor calls it
unconditionally (recovery has to run before any new statement does), so a
second `Executor` built against a `Database` that already has an
`Executor`-opened transaction in progress must not have construction
silently undo it out from under the first. The alternative — some
caller-visible "has this database already recovered" flag `Executor` has
to check before deciding whether to call `recover()` at all — pushes the
same bookkeeping onto every caller instead of the one class that actually
knows whether it has run.

## DDL is not transactional

`CREATE`/`DROP TABLE` and `CREATE`/`DROP INDEX` apply immediately, whether
or not a `BEGIN` is currently open, and are never written to the WAL — a
`ROLLBACK` after `CREATE TABLE posts (...)` leaves `posts` exactly as
created.

Making DDL transactional would mean the catalog itself — table and index
definitions, not just row data — needs undo entries and a place in the
WAL's record shapes, and every schema-reading path (`Database::table()`,
`heapFile()`, `index()`) would need to account for a table that
"exists" only inside an open, uncommitted transaction. Several real
databases (MySQL among them) make the same choice for the same reason:
DDL's effects are cheap to redo by hand if a mistake is caught immediately
after, and the machinery to make it fully transactional is disproportionate
to how often a schema change needs undoing compared to a row's data.

## A `LogicalPlan` node carries its own physical decision

`Sql\Planner\Plan\Scan::$index` and `Plan\Join::$hash` both start `null`
and get filled in by an `Sql\Optimizer\Rule\*` once one applies — the same
`Scan` or `Join` object changes from "not yet decided" to "decided", in
place, rather than a `LogicalPlan` tree being rewritten into a separate
`PhysicalPlan` tree the way PLAN.md's own file layout
(`Planner/LogicalPlan.php`, `Planner/PhysicalPlan.php`) sketches.

A real physical-plan split earns its keep when a planner has to keep more
than one candidate physical strategy alive at once to compare their cost —
that is what "logical" (the question) versus "physical" (one candidate
answer) is for. This optimizer never does that: every rule commits to its
rewrite immediately, there is no alternative plan sitting alongside the
chosen one to discard. Splitting the type in two here would mean two
parallel class hierarchies (`Plan\Scan` and, say, `PhysicalScan`)
representing the same node through two different points in its life,
doubling the types `Sql\Optimizer\Rule\*` and `Execution\Executor::compile()`
both have to know about for no reader benefit — nothing downstream needs
"has this node been decided yet" to be a difference in *type*, only in
whether a nullable field is still `null`.

## A predicate never pushes past an outer join's nullable side

`Sql\Optimizer\Rule\PredicatePushdown` will move a `WHERE` conjunct onto a
`LEFT JOIN`'s left side or a `RIGHT JOIN`'s right side, but never onto the
other, nullable one. This is not a narrower version of the rule for
simplicity's sake — it is the one restriction that has to hold for the
rule to be correct at all.

An outer join keeps an unmatched preserved-side row by null-padding the
columns that would have come from the other side. Moving a `WHERE`
condition on that nullable side to run *before* the join, instead of after
it as part of `WHERE`, changes what "unmatched" means: a right row that
fails the pushed-down condition now looks, to the join, exactly like a
right row that was never there in the first place, so the left row is kept
and null-padded — where evaluating the same condition *after* the join, as
written, would instead have discarded that row outright for failing a
`WHERE` clause on a real, matched value. These are different result sets
in general, not merely different plans for the same one — a `LEFT
JOIN ... WHERE right.col = x` that happens to reject nulls is the
well-known case where the two would coincide, but this rule does not
attempt to detect that case and push anyway; it always leaves the
condition where `WHERE` put it whenever the side is nullable.

## Join reordering is a two-table swap by page count, not a search

`Sql\Optimizer\Rule\JoinReordering` only ever does one thing: given an
`INNER JOIN` whose two sides are each directly a table (a `Scan`, or a
`Scan` under a `Filter` — not a nested `Join`), it compares
`HeapFile::pageCount()` for each side and swaps which one is `left` versus
`right` if that puts the smaller table on `HashJoin`'s hash-built `$right`.
A three-or-more-table query keeps exactly the join order its `FROM` clause
wrote, however each table's actual size compares.

Real query optimizers solve a harder version of this: given `N` tables and
a set of join conditions among them, search the space of legal join
orders (and of which pairs to join before which) for the cheapest overall
plan — an `O(N!)` space in the worst case, tamed in practice with dynamic
programming or heuristics, and dependent on cost estimates (row counts,
selectivity) this project has never needed to build for anything else.
Solving that properly is a project of its own, disproportionate to what
this rule needs to demonstrate: that the plan tree can carry a physical
decision an earlier phase's ad hoc code never got to make (`HashJoin`
always kept whichever side the `FROM` clause happened to name as `$right`),
and that even a single, narrow cost signal — a page count already sitting
in memory, not a fresh statistic gathered for this purpose — can improve a
plan a rule-based, non-cost-based optimizer would otherwise leave alone.
Extending it to a real join-order search is future work, named here rather
than attempted narrowly and incorrectly.

## A CHECK constraint's text is reparsed on every write, not cached

`ConstraintEnforcer::assertCheckConstraints()` calls
`Sql\Parser::parseExpression()` on a `CheckConstraint`'s stored text every
time it runs — once per `INSERT`/`UPDATE` statement, not once per
constraint ever. Caching the parsed `Expression` (keyed by constraint
name, say, invalidated whenever the table's schema changes) would save
that parse on every subsequent write.

The parse this avoids is small — a `CHECK` expression is typically one
comparison or a short boolean combination of a few, the same size of
expression `Sql\ExpressionPrinter` already round-trips through text for
storage — and it happens once per *statement*, alongside everything else
a single `INSERT`/`UPDATE` already does once per statement: resolving
column defaults, checking `UNIQUE` against an index, evaluating the
`SET`/`VALUES` expressions themselves. A cache would be one more thing to
invalidate correctly whenever a table's constraints change (`ALTER TABLE`,
once it exists) for a cost this project has not measured as worth avoiding.
If a profiled workload ever shows otherwise, the seam is exactly this one
call — nothing else would need to change to add a cache behind it.

## `ConstraintEnforcer` answers; `Executor` acts

`Execution\ConstraintEnforcer` only ever answers a yes/no question about
one row as given — is this `CHECK` satisfied, does this `FOREIGN KEY`
value exist in the referenced table — and never mutates anything. Deciding
*what to do* when a `FOREIGN KEY`'s referenced row is deleted or its key
changes (refuse, cascade, or null the child out) stays in
`Execution\Executor` instead, as `cascadeBeforeDelete()`/
`cascadeBeforeUpdate()`.

This mirrors the split Phase 6 already made between `Storage\BTreeIndex`
(a data structure) and `Execution\IndexMaintainer` (what keeps it in step
with a write) — and the reason is the same one that split gave: acting on
a cascade needs the heap file, the index maintainer, the WAL, and the lock
manager, all of which already belong to `Executor` and none of which
`ConstraintEnforcer` has any other reason to hold. Giving `ConstraintEnforcer`
all of that just so it could physically carry out a `CASCADE` would turn it
into a second `Executor` under a different name, duplicating machinery
that already exists once. Keeping it to pure answers also makes it usable
anywhere a plain "is this allowed" check is all that is needed — its own
unit tests, above all — without a `Database` standing in for a live
transaction it would otherwise have to fake.

## A cascade cycle is not detected

Two tables `CASCADE`-referencing each other, or a table `CASCADE`-
referencing itself in a way that never terminates, will recurse through
`Executor::cascadeDeleteChild()`/`cascadeBeforeDelete()` until the call
stack gives out, not a clean `ConstraintViolationException`.

A real database detects this by tracking which row a cascade is currently
processing (or by bounding recursion depth) and reporting a cycle
explicitly. This project's own self-referencing-`CASCADE` test
(`ExecutorConstraintTest::testASelfReferencingForeignKeyCascadesWithinTheSameTable`)
is the *acyclic* shape — a tree, walked correctly by the same recursion —
and that is the shape a self-reference realistically takes in most schemas
(a category tree, an org chart). A genuine cycle needs two rows to already
each point at a row that (transitively) points back at the first, which
`FOREIGN KEY` alone does not encourage anyone to build by accident the way
an unbounded recursive data structure might. Given that, detecting it
outright was judged not worth the added bookkeeping for this phase — a
named, narrow gap rather than a silent one, alongside `TransactionManager::recover()`'s
single-crash assumption (Phase 8) as another case where this project
accepted a bounded, documented blind spot over the complexity of covering
every pathological input.

## `MessageType` and `Opcode` are one enum, not two

PLAN.md's own project layout lists `Network/Protocol/MessageType.php` and
`Network/Protocol/Opcode.php` as two separate files. This implementation
has one: `Network\Protocol\MessageType`, an `int`-backed enum covering
every row of §5.3's message table.

Two names existing in a spec is not, by itself, evidence that two distinct
concepts exist behind them. Every other section of the protocol that talks
about "which message" — §5.3's table, §5.4's handshake sequence, the
`Frame`'s own `type` field — uses exactly one numbering scheme, the one
`MessageType` already carries. A separate `Opcode` type would need its own
answer to "what does an opcode name that a message type does not," and
nothing in PLAN.md gives one. Splitting them anyway would mean either a
duplicate enum with the same 25 cases under a different name (pure
repetition, and two places to keep in sync if either grows), or an
`Opcode`/`MessageType` pair that map to each other one-to-one everywhere
they are both used (a distinction the code would have to actively
preserve for a difference nothing depends on). Either way costs upkeep for
a distinction this project has never been asked to make. If a real reason
to separate them shows up — a message type that needs several opcodes
inside its own payload for sub-operations, say — the seam is exactly this
one enum, easy to split at that point with an actual example driving the
split.

## Wire values are self-describing, not typed by column

A `Message\Query`'s bound parameters and a `Message\QueryResultMessage`'s
row values are both encoded through `Network\Protocol\WireValue` — a value
prefixed with a one-byte tag naming its own PHP shape (`NULL`, `BOOL`,
`INT`, `FLOAT`, `STRING`, `DATETIME`) — rather than through
`Network\Protocol\ValueCodec`, the codec that already exists for a value
with a known `Schema\Type` (Phase 1).

Both of these genuinely lack that type. A bound parameter in `WHERE age >
?` has no type of its own until the query is planned and `?` is matched
against the `age` column — the same reason `Sql\Optimizer\Rule\ConstantFolding`
(Phase 9) refuses to fold a `Placeholder` at all. A `SELECT`'s output
column is even less likely to have one: `price * qty` and `COUNT(*)` are
both perfectly normal result columns with no single stored `Schema\Type`
behind them, computed fresh by `Execution\Expression\Evaluator`/
`Execution\Operator\Aggregate` from whatever the source rows held.
`ValueCodec::encodeValue()` requires a `Type` argument precisely because it
was built for the one case that always has one — a column being read from
or written to disk — and stretching it to cover these two by inventing a
type after the fact (inferring one from the runtime PHP value, say) would
recreate the exact ambiguity `TypeFactory::fromCode()` already refuses for
the same reason (see "Names rebuild a type, codes only classify"): a
`DECIMAL`'s scale, a `VARCHAR`'s length, cannot be recovered from a bare
value. Tagging the value with its own shape instead needs no inference and
no separate type channel on the wire at all.

## `QUERY_RESULT` drops the null bitmap and per-column type

PLAN.md §5.6 specifies a `type_code`/`flags` byte pair per column and a
null bitmap per row. `Network\Protocol\Message\QueryResultMessage` has
neither: each row's values are `WireValue`-tagged (see above), and a
column is sent as just its name.

The null bitmap is the smaller cut: once every value already carries its
own tag, `NULL` is one more tag among six, and a bitmap alongside it would
be encoding the same fact twice — a bitmap only earns its keep as a
*space* optimization over a per-value tag, which this protocol is not
tuned for yet (nothing here batches or compresses frames either). The
per-column `type_code`/`flags` cut follows from the same fact that drove
the choice above: `Execution\QueryResult` carries column *labels*, never a
`Schema\Type`, so there is no type to put in that byte for a computed
column — and forcing every query to resolve one, just to fill in a
descriptor real client code would then have to ignore for half its
columns anyway, is exactly the kind of table PLAN.md's own literal spec
did not anticipate needing. `WireValue`'s per-value tag already gives a
client everything `type_code` would have told it, on the one column where
it is actually knowable — every column, all the time — rather than a
best-effort guess sent alongside data whose real shape is decided row by
row.

## `Frame` and `FrameReader` exist with no socket behind them yet

Milestone 11 builds `Frame`, `FrameReader`, `Codec`, and every `Message`
class completely independent of any actual TCP connection — `FrameReader::feed()`
takes a plain string, not a socket resource, and every test in
`tests/Unit/Network/Protocol/` proves the whole protocol layer without
opening a port.

PLAN.md splits "define the wire protocol" (Milestone 11) from "build the
TCP server" (Milestone 12) into two separate milestones, and this
implementation takes that split literally: nothing about *what the bytes
mean* needs a live connection to prove, only *how they arrive* does — and
`FrameReader`'s whole job is already being agnostic to that, since a
socket's `fread()` can just as easily hand it one byte at a time as
everything at once. Building the socket layer first, then discovering the
frame format needs adjusting once real bytes are involved, would mean
redoing protocol-level work under server-level pressure; building it
without one first, and proving it against synthetic byte streams that
exercise every seam a real one could (a frame arriving in pieces, several
arriving at once, corrupted magic bytes) is the same testing this project
already relies on everywhere else that talks about files or the network
only through an interface (`Infrastructure\FileSystem`, `ValueCodec`) —
proving the logic before anything that could make a test flaky or slow is
attached to it.

## One process, one event loop — not a fork per connection

`Network\Server` serves every connection from a single PHP process, via
`Network\EventLoop`'s `stream_select()`-based reactor. PLAN.md §2.2 allows
either this or "fork/process pool" for multi-client mode; this
implementation only ever builds the event loop.

Forking has a real, named weakness in this project's own non-functional
requirements: "Windows with fork limitations" (§2.2) — `pcntl_fork()`
does not exist on Windows at all, so a forking server would need an
entirely separate code path there, or would simply not run. An event loop
built on `stream_select()` has no such gap: it is portable everywhere PHP
itself is. The other reason is `Schema\Database`'s own design: Phase 8
made `Wal`, `LockManager` and `TransactionManager` live on `Database`
specifically so that multiple `Execution\Executor`s talking to it share
one transaction slot and one lock table *in the same process*. A forked
worker per connection would put that shared state in *different*
processes, which then need it synchronized across a process boundary —
shared memory, or a lock service, neither of which this project has any
other reason to build. One process serving every session keeps every
connection's `Executor` pointed at the exact same in-memory `Database`
object Phase 8/9 already assumed, with nothing new required to make
`testASecondSessionSeesTheFirstsOpenTransactionAsTheSingleWriter`
(`ServerTest`) true.

The cost is real and accepted rather than ignored: one slow query blocks
every other session's socket from being serviced until it returns, since
nothing here is truly concurrent, only interleaved between I/O waits.
`Execution\Executor::run()` runs synchronously to completion inside
`Session::handleQuery()` — there is no `Fiber`, no cooperative yield mid-
query. For the workload this project targets (PLAN.md's own "up to
1,000,000 rows per table", not a high-concurrency OLTP service), that
trade is judged acceptable; revisiting it would mean either accepting
fork's platform gap or building real cooperative scheduling around
`Executor`, both bigger projects than this phase's goal ("server executes
SQL from a client") called for.

## `Server` tests drive `tick()` by hand over a real socket

`ServerTest` never calls `Server::run()`. It calls `Server::start()` (bind
and listen), connects a real client with `stream_socket_client()`, and
then calls `Server::tick()` in a small polling loop — reading from the
client socket after each call — until the expected response frame has
fully arrived.

The alternative that actually tests `run()` itself needs a second process
or thread the test can start the server in while the main test process
acts as the client — `pcntl_fork()`, most likely, forking the test process
itself to run the blocking loop in the child. That works, but trades a
plain, synchronous PHPUnit test for one that has to synchronize across a
process boundary (the parent must somehow know the child has bound its
socket before connecting, and must reliably clean up the child afterward
even when an assertion fails) to prove a fact — "the event loop correctly
serves a request" — that `tick()` alone already proves without any of
that machinery. `run()` itself is left to add almost nothing on top:
`while (!stopped) tick()` plus the two `pcntl_signal()` registrations,
neither of which needs a second process to verify were it ever tested
directly. Every real socket-level behavior this phase claims — a
handshake, a query answered, `PING`/`PONG`, a disconnect noticed, a
second connection refused over the limit — is proven exactly as it would
happen against a real, independently-running server, just paced by the
test instead of by `stream_select()`'s own timeout. `bin/minidb-server`'s
own manual smoke test (run once, by hand, against the actual compiled
entrypoint) is what closes the remaining gap — proving `run()` and the
signal handlers work as a real standalone process — without needing that
proof repeated, and slowed down, on every test run.

## An unhandled message type closes the connection

`Network\Session::handleFrame()` matches exactly four message types
(`Hello`, `Query`, `Ping`, `Goodbye`); anything else — `Auth`, `Prepare`,
`Execute`, the typed `Begin`/`Commit`/`Rollback`/`Savepoint` — closes the
connection outright, logged as a warning, rather than being answered with
some error message or quietly dropped.

Silently dropping it would leave the client waiting for a reply that will
never come, indistinguishable from a hung server. Answering it with a
`QueryError` was considered and rejected: that message's whole meaning is
"the query you sent failed," and none of these are a failed query — a
`Prepare` half-implemented into an error reply would tell a client
"prepare this statement" and "your statement was rejected" through the
same shape, which is actively misleading about what the server can and
cannot do. Closing the connection is the honest signal: nothing this
phase does is a substitute for the milestone that actually implements
each of these (`Auth`/authentication is Milestone 13; `Prepare`/`Execute`
is Milestone 14; the typed transaction messages are Milestone 15), and a
client that sent one is talking to a server it should not assume supports
it yet.

## A user's salt is derived from their username, not stored

`Network\Auth\PasswordHash::saltFor()` computes a user's salt as
`SHA-256(username)`, truncated to the length `sodium_crypto_pwhash()`
requires. PLAN.md §6.4's own `users.json` example shows a random `salt`
field stored alongside `hash`; this project's `users.json` has no such
field at all.

The reason is the handshake sequence PLAN.md §5.4 itself lays out:
`HELLO` → `HELLO_ACK` → `AUTH { username, response }` → `AUTH_OK`/`AUTH_FAIL`.
`HELLO_ACK` is sent before the server has been told which user is
connecting — `Hello`'s own fields carry no username — so at that point
there is no per-user salt the server could hand back even if one were
stored. A real client computing `response = HMAC(hash, nonce)` needs
`hash = Argon2id(password, salt)` computed with the *same* salt the server
used when the account was created, and PLAN.md's own message table has no
message shaped "tell me user X's salt" for a client to ask with before
sending `AUTH` — real SCRAM (RFC 5802) has an extra round trip for exactly
this (client-first, server-first carrying the salt, client-final), which
this project's four-message sequence deliberately does not reproduce
(PLAN.md §1.1 itself calls this "SCRAM-like", not SCRAM).

Deriving the salt from the username removes the need for that round trip
entirely: both sides can compute the identical salt from information they
already have (a username, typed by the person connecting) with no lookup.
The cost is real and named, not hidden: renaming a user silently
invalidates their password (their derived salt changes), and an attacker
who already knows a specific username could precompute a rainbow table
*for that one account* ahead of time — weaker than a long random salt
against a targeted attacker, but not weaker than having no salt at all
(the actual failure mode a salt exists to prevent — the same hash
appearing for the same password across every account). No password reset
or user rename exists in this project yet for the first cost to bite, and
the second is judged acceptable for a learning project's threat model, the
same way `Storage\Catalog`'s composite-index gap or `TransactionManager`'s
single-crash assumption are: a real, bounded trade-off, not a rigorous
production posture.

## Password hashing uses raw `sodium_crypto_pwhash()`, not `password_hash()`

`Network\Auth\PasswordHash::derive()` calls `sodium_crypto_pwhash()`
directly, asking for raw output bytes, rather than PHP's more usual
`password_hash()`/`password_verify()` pair (which also supports Argon2id,
via `PASSWORD_ARGON2ID`).

`password_verify()` only ever answers a yes/no question — it is built
specifically so the raw hash bytes never have to leave the function that
checks them. That is exactly the wrong shape for PLAN.md §5.5's challenge-
response scheme, which needs *both* the client and the server to
independently arrive at the identical byte string and use it as an
`HMAC` key (`Network\Auth\ScramChallenge::respond()`) — proving the
client knows the password without the password, or anything derived
one-way from it, ever crossing the wire. `password_hash()` also picks its
own random salt internally and folds it into the returned PHC string,
which is right for its own use case (verifying a login against a database
directly) and wrong for this one, where the salt has to be something both
sides can derive *identically* and independently (see the entry above).
`sodium_crypto_pwhash()`'s raw-output mode is the primitive that actually
fits: same algorithm family (Argon2id), same PLAN.md §13.1 requirement,
but bytes a caller can use as a key instead of bytes only `password_verify()`
can read.

## Login throttling is per username, in memory, for the process's life

`Network\Auth\LoginThrottle` tracks failed attempts keyed only by
username, held in a plain array for as long as the server process runs —
nothing is written to disk, and a restart forgets every lockout.

This is the same scope `Transaction\LockManager` settled for the same
reason (Phase 8): there is one process, nothing else needs this state,
and persisting it would mean answering questions (how long does a lockout
survive a restart? does every session need to see the same throttle
state, which they already do since they share one `Network\Server`
process) that a learning project's threat model does not need answered
today. Keying by username alone, not also by the connection's remote
address, is a narrower and more consciously incomplete choice: a real
deployment wants both, since a username-only throttle cannot slow down an
attacker spraying many *different* guessed usernames from one machine,
only one who keeps guessing the same account. `Network\Session` does not
currently read a peer address at all (Milestone 12 never needed one), so
adding IP-based throttling now would mean building that plumbing for a
single caller — deferred, named, not silently absent.

## User management is its own local CLI script, not a network client command

`bin/minidb-user add/remove/list` edits `users.json` directly through
`Network\Auth\UserStore` — it never opens a TCP connection. PLAN.md §9.2
shows the identical three subcommands as `php bin/minidb user add ...`,
under the same `bin/minidb` PLAN.md reserves for the full network client
(`connect`, `query`, `shell`, `import`, `export`).

That client cannot exist yet: it needs `Client\Connection` and the rest of
the PHP client library, which is Milestone 16's job, several milestones
after this one. Building a real `bin/minidb user` subcommand now would
mean either faking a client-side connection just for this one feature, or
quietly writing straight to `users.json` from inside what is supposed to
be *the network client* — hiding a local file operation behind a name
that promises a network one. A separate, honestly-named script that does
exactly what it says avoids both: it is what a real database's admin
tooling often provides anyway (editing a credentials store directly,
before or without a running server), and `bin/minidb user` remains free
for Milestone 16 to implement for real, as a thin wrapper that actually
does go over the wire once a client exists to send `AUTH` through.

## A prepared statement is parse-once, not plan-once

`PREPARE` caches the `Sql\Ast\Statement` `Sql\Parser::parseOne()` produces,
not a `Sql\Planner\LogicalPlan` or anything the optimizer has already
worked on. Every `EXECUTE` hands that same `Statement` straight to
`Execution\Executor::execute()`, the method `Query`'s own `run()` already
calls after its own `parseOne()` — so a prepared statement's SQL text is
genuinely parsed exactly once no matter how many times it runs, but a
`SELECT` is still planned and optimized fresh on every `EXECUTE`.

A real database usually caches the plan too, since planning can cost more
than parsing once a query is complex. This project does not, for a
concrete reason: `Executor::plan()` calls `Optimizer::optimize()` fresh
against the *current* catalog and (for `IndexSelection`/`JoinReordering`)
the *current* `Storage\HeapFile::pageCount()` of every table involved, so
a plan cached at `PREPARE` time could go stale the moment a `CREATE INDEX`
or a large `INSERT` runs on a long-lived connection before that statement
is next executed — nothing here invalidates a cached plan when the
catalog or table sizes change. Re-planning on every `EXECUTE` sidesteps
that whole problem rather than solving it, at the cost of being a smaller
performance win than "prepared statement" usually promises. `PREPARE`
itself does no semantic validation beyond parsing for the same kind of
reason real databases often defer it: a table `PREPARE` mentions is
allowed not to exist yet, so long as it exists by the time `EXECUTE` runs.

## PREPARE and CLOSE_STMT failures reuse QueryError

PLAN.md's message table gives `PREPARE` exactly one reply, `PREPARE_OK`,
and gives `CLOSE_STMT` none at all — neither has a dedicated failure
message, the same gap `EXECUTE` already has (it, too, has no reply of its
own beyond the `QUERY_RESULT` any successful statement produces). Rather
than invent wire messages PLAN.md does not define, a failed `PREPARE`
(bad SQL, or `ServerConfig::$maxPreparedStatements` already reached) and a
failed `EXECUTE` (an unknown or already-closed statement id, or the
statement itself failing) all answer with the same `Message\QueryError`
`Network\Session::handleQuery()` already sends for a failing `Query` —
one error shape a client has to understand regardless of which message
provoked it, rather than three.

`CLOSE_STMT` closing an id that is unknown, or was already closed, is not
treated as an error at all — there being no reply to put one in is only
part of the reason; the more important one is that a fire-and-forget
"I am done with this" message being idempotent is the expected case for
a resource-release call, not a special one worth a warning over.

## A session's disconnect rolls back its own open transaction

`Schema\Database` allows only one open transaction at a time, system-wide
(see [Database, not Executor, owns transaction and lock state](#database-not-executor-owns-transaction-and-lock-state)),
which means a session that runs `BEGIN` and then disconnects without
`COMMIT`/`ROLLBACK` — a crash, a dropped connection, a client that simply
forgets — would otherwise leave that transaction open forever. Every other
session's `BEGIN` is refused outright while one is already open
(`Transaction\TransactionManager::begin()` throws rather than queuing), so
an abandoned transaction does not just inconvenience the client that
vanished — it locks every future connection out of writing anything, for
good, until the process restarts.

`Network\Session::close()` now rolls back automatically when this session
is the one holding it open. The harder question was *how* to know that: a
transaction is not tagged with the session that opened it anywhere in
`Transaction\TransactionManager` or `Transaction`, and adding that tracking
there would mean threading a session identity through `Execution\Executor`
and `Schema\Database`, both of which are otherwise connection-agnostic —
`Executor` does not know it is being called from a network session at all,
and should not have to.

Instead, `Session` watches its own statement's effect: `Execution\Executor::inTransaction()`
(new this phase, forwarding to `TransactionManager::inTransaction()`) is
checked immediately before and immediately after every statement this
session runs, whichever message it arrived as. A `false → true` transition
can only be this session's own successful `BEGIN` — a second one is
refused while another is open, so nothing else could have caused the flip
between the two checks in a single-process, single-threaded event loop.
Symmetrically, any transition to `false` (a `COMMIT` or `ROLLBACK` that
actually ran) means nothing is open for anyone to abandon any more,
regardless of which session's statement caused it. This correctly leaves a
session that never opened a transaction, or already closed the one it did
open, with nothing to roll back on disconnect — proven by
`ServerTransactionTest::testDisconnectingOutsideATransactionDoesNotAffectAnotherSessionsOpenOne()`.

One gap this does not close: `TransactionManager::commit()`/`rollback()`
have no ownership check at all today — a session that never called `BEGIN`
can `COMMIT` or `ROLLBACK` whatever transaction another session currently
has open, and `TransactionManager` will do it. This predates this phase
(single-writer transactions have worked this way since Phase 8) and stays
unresolved here too: fixing it would mean `TransactionManager` tracking
which session opened a transaction, which is exactly the coupling the
design above avoids. Left as a named gap rather than solved by accident as
a side effect of this phase's actual scope.

## Typed transaction messages wrap the same statement classes Query already ran

`BEGIN`/`COMMIT`/`ROLLBACK`/`SAVEPOINT` over the wire (`Network\Protocol\Message\Begin`
etc., defined in Phase 11) do not reimplement transaction control a second
time: `Network\Session::handleBegin()` and its three siblings each build
the same `Sql\Ast\BeginStatement`/`CommitStatement`/`RollbackStatement`/`SavepointStatement`
`Sql\Parser::parseOne()` would have produced from `BEGIN`/`COMMIT`/`ROLLBACK`/
`SAVEPOINT name` as plain SQL text, and hand it to `Execution\Executor::execute()`
— the exact same method `Query`'s `run()` and `EXECUTE` already call after
their own parsing. The alternative — calling `Executor`'s private
`executeBegin()`/`executeCommit()`/etc. methods directly, or duplicating
their bodies in `Session` — would mean two paths to the same behavior that
could silently drift apart; building the small, cheap AST node instead
keeps there being exactly one way a `BEGIN` (however it arrived) actually
runs.

A consequence, not a cost: any error a plain-SQL `BEGIN`/`COMMIT`/`ROLLBACK`/
`SAVEPOINT` could already raise (`Exception\TransactionException` for a
second `BEGIN`, a `COMMIT` with nothing open, an unknown savepoint name)
comes back through the typed path too, reported the same way `Query`
already reports it — a `QueryError` with `ErrorCode::TRANSACTION_ERROR`,
not a new wire error code invented for the typed messages specifically.

`ROLLBACK TO SAVEPOINT` and `RELEASE SAVEPOINT` get no typed wire message
of their own: PLAN.md §5.3's message table defines `SAVEPOINT` (`0x23`)
alone among the four keywords Phase 8's `Sql\Ast` already distinguishes
(`SavepointStatement`, `RollbackToSavepointStatement`, `ReleaseSavepointStatement`).
Rather than inventing two message types the protocol's own spec does not
have, both stay reachable exactly the way they already were before this
phase: as plain SQL text through `Query`.

## Connection uses stream_select for its own read/write timeouts

`Client\ClientConfig` gives a caller three independent timeouts:
`$connectTimeoutSeconds`, `$readTimeoutSeconds`, `$writeTimeoutSeconds`.
The obvious PHP tool for the latter two, `stream_set_timeout()`, does not
actually give them independence — it sets one shared timeout PHP applies
to whichever blocking stream operation happens to be waiting, not a
separate read budget and write budget. Using it would mean
`ClientConfig`'s two fields promise something the mechanism underneath
cannot deliver.

`Client\Connection` puts its socket in non-blocking mode instead (the
same choice `Network\Acceptor` already made server-side) and waits for
readability or writability explicitly with `stream_select()` before every
`fread()`/`fwrite()`, tracking its own deadline in PHP (`microtime(true)`
plus the configured budget, decremented across however many partial reads
or writes a large frame takes). This is the same primitive
`Network\EventLoop` already uses to watch many sockets at once
server-side (see [`Server` tests drive `tick()` by hand over a real
socket](#server-tests-drive-tick-by-hand-over-a-real-socket)); here it is
called once per blocking-looking request instead of once per event-loop
tick, but it is the same mechanism, not a second one invented for the
client side.

The write-timeout half of this is real, working code, but is not
independently exercised by a test: reliably forcing a write to a loopback
socket to actually block requires its kernel send buffer full, and
filling that deterministically (without a flaky, environment-dependent
amount of data or an artificially shrunk buffer) is not something this
suite attempts. The read-timeout half *is* tested directly
(`ConnectionTimeoutTest`), since simulating "the other side never
replies" needs nothing more than a listening socket that accepts a
connection and stays silent — no buffer-filling trick required. This
mirrors the project's existing practice of documenting a genuinely hard
to test timing behavior rather than writing a slow or flaky test to chase
full coverage of it (see `ServerConfig::$idleTimeoutSeconds`'s own gap).

## ConnectionPool tests a connection on acquire, not on release

PLAN.md §2.1 asks for "reconnect on failure" from the client's connection
pool. Two points to check a pooled connection's health: when a caller
returns it (`release()`) or when the next caller asks for one
(`acquire()`). Checking on `release()` would mean paying a `PING`/`PONG`
round trip on every single release whether or not the connection is ever
reused again before the pool is closed; checking only on `acquire()`
("test on borrow") pays that cost exactly once per connection that is
actually about to be reused, and never at all for one released right
before the pool itself is closed.

`ConnectionPool::release()` therefore does nothing but push the
connection onto the idle list, unconditionally — it has no opinion on
whether the connection is still good. `acquire()` is the one place that
calls `Connection::isAlive()` on a reused connection, and calls
`Connection::reconnect()` on it — in place, keeping the same `Connection`
object identity — if it is not. A connection is never reconnected
*mid-request*, only in the gap between one caller's `release()` and the
next caller's `acquire()`, since transparently retrying whatever the
previous caller was doing (inside `query()`/`execute()` itself) could
silently replay a write that had already reached the server once — a
correctness hazard real connection pools are usually careful to avoid,
and this one is too.

## Client tests run a real bin/minidb-server child process

Every `Network\Server` test up to this phase (`ServerTest` and its
siblings) drives `Server::tick()` by hand, one call at a time, from
inside the test method itself — documented in [`Server` tests drive
`tick()` by hand over a real socket](#server-tests-drive-tick-by-hand-over-a-real-socket).
That only works because the test method is *also* the thing making the
client-side calls on the same real socket: nothing blocks waiting for the
server, since the test alternates "poke the server" and "check the
client's socket" itself.

`Client\Connection` breaks that trick by design: its entire public API is
one blocking-looking call per request (`$conn->query(...)` sends and then
waits for its own reply before returning), because that is what a real
caller actually wants from a client library. A test written the same way
`ServerTest` is — one PHPUnit process, an in-process `Server`, `tick()`
called by hand — would deadlock the moment it tried to call
`Connection::connect()` or `query()`: nothing would ever run `tick()`
while that call is blocked waiting for a reply, since the test method
*is* the only thing that could call it, and it is not free to until the
blocking call returns.

`tests/Support/RunningServer` resolves this the direct way: it launches
the real, unmodified `bin/minidb-server` as a genuine child process via
`proc_open()`, polls a raw socket connect until it is actually listening,
and tears it down with `proc_terminate()`/`proc_close()` afterward. This
is a new testing pattern for this project — no earlier phase spawns a
subprocess — but it is also the more honest one for this specific layer:
a `Client\Connection` is built to talk to a server running in a genuinely
separate process, so testing it against exactly that, rather than against
an in-process simulation convenient for the *server's* own tests, is what
actually proves it works. Each test gets its own fresh subprocess and
temporary data directory (matching the granularity `ServerTest` already
uses per test method), and a fixed, distinct port per test class rather
than an OS-assigned one — `port: 0`'s actual bound port is only knowable
in-process (`Server::localAddress()`), and `bin/minidb-server` as a
subprocess exposes no equivalent way to report it back.

## User management stays local, now under bin/minidb itself

Supersedes [User management is its own local CLI script, not a network
client command](#user-management-is-its-own-local-cli-script-not-a-network-client-command),
Phase 13's decision — written before this project's own wire protocol was
fully built out, on the expectation that "Milestone 16 [would] implement
[`bin/minidb user`] for real, as a thin wrapper that actually does go
over the wire once a client exists to send `AUTH` through." That
expectation turned out wrong once there was an actual client to check it
against: PLAN.md §5.3's message table has no wire operation for creating,
removing or listing users at all — `HELLO`/`AUTH`/`QUERY`/`PREPARE`/
`EXECUTE`/transaction control/`PING`/`GOODBYE`/`SHOW_STATUS`/
`SHOW_CONNECTIONS`/`KILL`, and nothing named "add a user." There was
never a wire request for `bin/minidb user` to become a "real" thin
wrapper around.

Given that, `user add`/`remove`/`list` stays exactly what Phase 13 built:
a local edit to `users.json` through `Network\Auth\UserStore`, no
connection opened. What changes this phase is only where it lives —
`bin/minidb-user`, the standalone script Phase 13 built because
`bin/minidb` did not exist yet, is retired, and its logic moves to
`Cli\Command\UserCommand`, reached as `bin/minidb user ...` under the one
entrypoint PLAN.md's own file layout (§4) always showed. Inventing a wire
message now just to make the "real client, real wire request" version of
this decision come true would be protocol design nobody asked for —
Milestone 17's own checklist is about the CLI's shape, not the protocol's.

## import/export are scoped to what the protocol supports

PLAN.md §9.2 shows `bin/minidb export --host ... --user alice --output dump.sql`
with no table named — implying, by its shape, that it dumps an entire
database on its own. That is not buildable with what this project's
protocol and SQL grammar actually expose: there is no `SHOW TABLES` (no
SQL statement, no wire message) for a client to discover which tables
exist, and no way to ask the server for a table's *schema* either (no
`SHOW CREATE TABLE`, no equivalent message). A client genuinely cannot
find out "what is in this database" beyond what it is told to look at.

`Command\ExportCommand` is scoped to what actually is answerable:
`--table <name>` (repeatable) names every table to export explicitly, and
for each one, `SELECT *` plus its `Client\ResultSet::columns()` is enough
to write `INSERT INTO table (col, ...) VALUES (...);` for every row —
data, not schema, since there is no way to produce a `CREATE TABLE` for a
table the client has never been told the definition of. `Command\ImportCommand`
consequently expects the target tables to already exist; a dump this
project's own `export` writes is exactly built to satisfy that.

This is not the full "dump the database" tool PLAN.md's example gestures
at, and closing that gap for real would mean adding an introspection
statement or message this project does not have — a protocol change well
outside "CLI Client and REPL" territory. Scoping down to what is honestly
buildable now, and naming the gap here, was preferred over either
inventing new protocol surface mid-milestone or building something that
only *looks* like it dumps a whole database.

## Repl's statement completeness check is tokenize-and-look-at-the-last-token

`Cli\Repl` reads one line at a time and has to decide, after each one,
whether the buffered input so far is a complete statement (run it) or
not (read another line, with a continuation prompt) — the same problem
every interactive SQL shell has for `CREATE TABLE users (\n  id INT\n);`
spanning several lines.

The check is: try `Sql\Lexer::tokenize()` on the whole buffer; if it
throws, the input is not complete yet (an unterminated string is exactly
this case — `SELECT 'still typing` fails to tokenize, which is read as
"needs more input," not an error); if it succeeds, look at the last
non-`EOF` token and check whether it is a `;`. This reuses the same
lexer the server itself parses with, rather than a second, hand-rolled
"does this look finished" heuristic that could disagree with what the
server would actually accept — the REPL's idea of "complete" and the
parser's idea of "complete" are, by construction, the same idea.

A buffered, complete line can still hold more than one statement
(`SELECT 1; SELECT 2;`) — `Cli\SqlSplitter` (the same class
`Command\ImportCommand` uses for a dump file) is what splits it once the
completeness check passes, and each resulting statement is run in turn.

## Connection::requireOpen() guards against a TypeError

Found while writing `ReplTest`'s "the connection dies mid-session" case:
calling `Client\Connection::query()` (or any other method that sends a
message) on an already-`close()`d connection crashed with an uncaught
`TypeError` from `stream_select()` — "supplied resource is not a valid
stream resource" — rather than the `ClientException` every other failure
mode in this class produces. `stream_select()` fails *gracefully* (a
warning, `false` returned) for a socket that is merely broken, but throws
outright for one that has actually been `fclose()`d — a real, sharp edge
`@`-suppression does not soften, since PHP 8 raises this as an exception,
not the warning `@` exists to silence.

`Connection::requireOpen()` checks `$closed` at the top of both `send()`
and `receive()` — the two methods every public method here funnels
through — and throws a plain `ClientException('This connection is
closed.')` before any resource ever reaches `stream_select()`. Fixing it
here needed one more change: `reconnect()` used to flip `$closed` back to
`false` only *after* a successful `handshake()`, which would have made
its own internal `send()`/`receive()` calls trip the new guard on every
reconnect. It now flips `$closed` to `false` right after opening the
fresh socket, before calling `handshake()` — matching how a freshly
`connect()`ed `Connection` already starts out (`$closed` defaults
`false` before its own first `handshake()` runs) — and only sets it back
to `true` if that handshake then actually fails.

## SHOW_STATUS/SHOW_CONNECTIONS/KILL are typed messages only

PLAN.md §10.4 illustrates `SHOW STATUS;`, `SHOW CONNECTIONS;`, `SHOW
TABLES;`, `SHOW INDEXES FROM users;` and `KILL 42;` together, as if all
five were SQL statements this project's grammar understands. Milestone
18's own checklist, though, only ever claims three of the five —
`SHOW STATUS`, `SHOW CONNECTIONS`, `KILL <id>` — and `Network\Protocol\Message\ShowStatus`/
`ShowConnections`/`Kill` already existed as typed wire messages since
Phase 11, with `ShowStatus`'s own docblock naming Milestone 18 as the one
that would decide what they answer with. `SHOW TABLES`/`SHOW INDEXES
FROM` are not claimed by any milestone's checklist at all and stay
unbuilt (a real, separate gap — see [import/export are scoped to what the
protocol supports](#importexport-are-scoped-to-what-the-protocol-supports),
which already leans on this).

Given that, this phase implements `SHOW_STATUS`/`SHOW_CONNECTIONS`/`KILL`
purely as the typed messages they already were, in `Network\Session` —
not as new SQL grammar. Extending `Sql\Lexer`/`Sql\Parser`/`Sql\Ast` to
parse `SHOW STATUS` as a real statement would mean new token types, a new
AST node, and `Execution\Executor` dispatch for something that already
has a working, purpose-built wire message — solving a problem this
project does not have, for three commands out of PLAN's five that would
still leave the other two (`SHOW TABLES`/`SHOW INDEXES`) just as
unimplemented as before.

The illustration is not abandoned, though: `Client\Connection::showStatus()`/
`showConnections()`/`kill()` are real methods a caller can use directly,
and `Cli\Repl` recognizes the exact plain text PLAN.md shows
(`Cli\AdminCommand::parse()`) and translates it to those same typed
calls — close enough to feel like the example, at a fraction of the cost
of teaching the parser new grammar for three administration commands
alone. `bin/minidb`'s one-shot CLI gets `status`/`connections`/`kill <id>`
as real subcommands instead of SQL-shaped text, consistent with `user`/
`import`/`export` already being subcommands rather than text a client has
to sniff out of a `Query`.

## Server::serviceSession() sweeps every session for isClosed()

Found while testing `KILL`: closing a session from *inside a different
session's* `handleFrame()` call (exactly what `KILL` does —
`SessionManager::find()` plus that session's own `close()`) used to leave
`Network\EventLoop` still watching the now-`fclose()`d socket, because
the only place anything ever checked `Session::isClosed()` was
`Server::serviceSession()`, and only for the *one* session whose own
callback had just run. The next `tick()`'s `stream_select()` would then
be handed an already-closed resource and throw a `TypeError` — "supplied
resource is not a valid stream resource" — rather than failing gracefully
the way a merely broken (not closed) socket does. The same sharp edge
`Client\Connection::requireOpen()` (Phase 17) already guards against on
the client side, just discovered on the server side this phase, by the
one new feature (`KILL`) that lets one session's handling touch another's
socket at all.

`serviceSession()` now sweeps `SessionManager::all()` for any session
reporting `isClosed()`, not only the one it was just servicing, right
after every callback runs. This is a small, constant amount of extra work
per `tick()` (one cheap boolean check per open session) next to the
`stream_select()` call already happening once per tick regardless of how
many sessions are open — negligible, and it catches a `KILL`-closed other
session the moment it happens, in the same tick that caused it.

## bin/minidb-server's PID-file lifecycle never talks to the server

`stop`, `status` and `reload` (PLAN.md §9.1) never open a `Client\Connection`
to the server they are acting on, even though one now exists (Phase 16).
There is nothing a connection would buy any of the three: "is this pid
alive" (`status`, and the check `stop`/`reload` both do before acting) is
`posix_kill($pid, 0)`, a plain OS operation; "ask it to stop" is
`posix_kill($pid, SIGTERM)`; "ask it to reload" is `posix_kill($pid, SIGHUP)`.
Opening a real connection just to send a signal the OS already lets a pid
file address directly would add a dependency on the server actually
answering `HELLO` (impossible if it is genuinely wedged, which is exactly
when `stop` most needs to work) for no benefit over the pid + signal pair
every Unix service manager already uses for the same three operations.

`Cli\PidFile` is the one class both halves of this phase's CLI work
share: `Command\ServeCommand::run()` (`start`) writes it and removes it
on clean shutdown; `Cli\ServerApplication`'s inline `stop()`/`status()`/
`reload()` only ever read it and signal the pid it names.

## SIGHUP reload is scoped to max_connections only

PLAN.md's checklist says "Handle `SIGHUP` (reload)" without saying what
should actually reload — the natural reading, "reload `config/server.php`",
is not buildable here: no milestone's checklist ever claims loading that
file (see [bin/minidb-server's own docblock](#bin-minidb-servers-pid-file-lifecycle-never-talks-to-the-server)'s
neighboring reasoning), so there is no config source to re-read from at
all.

What actually *is* reloadable without one: almost nothing.
`ServerConfig::$host`/`$port` are the listening socket a restart would be
needed to rebind anyway; `$dataDirectory` is the one `Schema\Database`
already open, and reopening a different one mid-flight would mean
draining every in-flight session first — a much bigger undertaking than
"handle a signal." `$maxConnections`, read fresh from
`MINIDB_MAX_CONNECTIONS`, is the one setting that can genuinely change
while the process keeps running — `Network\SessionManager::setMaxConnections()`
just updates a plain integer field nothing else depends on being fixed at
startup. `Server::reload()` does exactly that and nothing else, and its
own docblock says so plainly rather than implying a fuller "reload the
config" this project cannot yet do.

## --daemon refuses to run without a real --log-file

`Command\ServeCommand`'s daemonizing (`pcntl_fork()`, `posix_setsid()`,
closing `STDIN`/`STDOUT`/`STDERR`) detaches the process from the
terminal that started it — after that point, nothing can ever read
whatever `Infrastructure\Logger` writes to `php://stdout`/`php://stderr`
again. Rather than daemonize into that silence and leave an operator
wondering why a backgrounded server produces no logs anywhere, `start
--daemon` checks `--log-file`/`MINIDB_LOG_FILE` first and refuses
outright, with a clear message, if it is still one of those two terminal
streams (or left at `Logger`'s own default, which is `php://stderr`).
A caller who wants a silent daemon can still point `--log-file` at
`/dev/null` explicitly — this only refuses the case where losing every
log line was probably not the intent.

## Dumper/Restorer/BackupManager are embedded-only

PLAN.md §11 Milestone 19's checklist says "Works via server and
embedded" without spelling out what that means for classes that talk in
SQL text and tar archives, not wire messages. The literal reading —
`Dumper`/`Restorer` also work over a live `Client\Connection` — turns out
not to be buildable as anything more than what `Cli\Command\ExportCommand`/
`ImportCommand` (Phase 17) already are: there is no `SHOW TABLES`, so a
network-mode `Dumper` could not discover tables on its own, and no way to
ask a remote server for a table's schema, so it could not emit `CREATE
TABLE` either — exactly the two things an embedded `Dumper` can do that
the network path cannot (see [import/export are scoped to what the
protocol supports](#importexport-are-scoped-to-what-the-protocol-supports),
the same gap, again).

Read instead as "the mechanism is embedded — it works whether invoked
*through* `bin/minidb-server`'s own CLI, or directly, embedded, in a
user's own PHP script" (`new Dumper($database, $executor)`), both
readings are satisfied by the same three classes, with no protocol work
at all: `Dumper`/`Restorer` operate on `Schema\Database`/`Execution\Executor`
directly, and `bin/minidb-server dump`/`load` are just a thin CLI wrapper
around exactly that embedded API, the same relationship `bin/minidb-server start`
already has to `Network\Server`.

`bin/minidb backup`/`restore` (recognized, stubbed since Phase 17) are
real now too, using `BackupManager` — and stay local filesystem
operations under the network client for the same reason `user add/remove/list`
already is one: a caller who can see the server's data directory runs
them directly, and there is nothing a round trip through `Client\Connection`
would add over reading the files (or, for `user`, `users.json`) in place.

## Dumper orders tables by foreign-key dependency, best-effort

A dump's `CREATE TABLE` statements (and, for the same reason, its
`INSERT`s) have to create parent tables before children that reference
them, or a `Restorer` replaying the dump from scratch would fail —
`Schema\Database::tableNames()` lists tables alphabetically (confirmed
directly: "orders" sorts before "users" even though "users" must be
created first when "orders" references it), not in any dependency-aware
order.

`Dumper::orderedByDependency()` does a small, direct topological sort:
repeatedly picks a table whose foreign keys all point at tables already
ordered (or at itself — a self-reference is never a blocker), and stops
making progress a round early rather than looping forever the moment
nothing is eligible — which only happens for a genuine cycle, or a
foreign key pointing outside the set of tables being dumped. Either case
falls back to appending whatever tables are left in their original order,
the same honesty `Execution\Executor`'s own cascade handling already has
about not detecting a cycle (Phase 10) rather than a claim of solving it.

Not handled at all: a self-referencing table (`employees.manager_id ->
employees.id`) whose *rows*, not just its table definition, point
forward — a report inserted before their not-yet-restored manager. Fixing
that would need either deferred constraint checking or a two-pass
insert-then-fix-up dump, neither of which this phase builds; a named,
narrow gap rather than a silent one.

## BackupManager uses PharData, not a shelled-out tar binary

A tar.gz archive of a whole data directory could be built either by
shelling out to a real `tar` binary (`proc_open`/`exec`) or through
`ext-phar`'s `PharData` class, which can build and read `.tar`/`.tar.gz`
archives directly in PHP. `PharData` was chosen, consistent with this
project's general preference for a PHP-native mechanism over an external
process where one already exists in the runtime — `stream_socket_*`
instead of a network tool, `pcntl`/`posix` instead of shelling out to
process-management commands (Phase 18). A `tar` dependency would also be
a new, unstated requirement on the deployment environment; `ext-phar` is
already part of a standard PHP build.

One real wrinkle, checked directly rather than assumed: `phar.readonly`
(`On` by default) does *not* block `PharData` the way it blocks a plain
executable `.phar` — verified by building and extracting a `.tar.gz`
archive with the default `php.ini` unchanged. No php.ini change is
required to use this class.

## BackupManager.restore() refuses a non-empty target directory

`PharData::extractTo($dataDirectory, null, true)`'s own overwrite
behavior merges an archive's files into whatever is already in the
target directory — for two arbitrary files, an unsurprising thing to
want; for a *database's* data directory specifically, silently
interleaving two different databases' catalogs, tables and WAL files
would leave neither one intact, with no error to say so.

`restore()` checks first: a non-empty target directory raises
`Exception\StorageException` unless the caller explicitly passes `force:
true`. `bin/minidb restore` surfaces this as its own `--force` flag
rather than always passing `force: true` — a caller has to mean it before
extracting over whatever is already there.

## StatementSplitter moved from Cli\ to Sql\

`Cli\SqlSplitter` (Phase 17) existed to split a dump file or a REPL line
into individual statements, since `Message\Query`/`Execution\Executor::run()`
only ever handle one at a time — used by `Cli\Command\ImportCommand` and
`Cli\Repl`. `Backup\Restorer` (this phase) needs exactly the same
splitting to replay a `Dumper`-produced dump, and `Backup\` has no
business depending on `Cli\` — a CLI-namespaced class existing purely to
be reused by a non-CLI one would have the dependency arrow pointing the
wrong way.

Moved to `Sql\StatementSplitter` instead (same logic, `Lexer::tokenize()`
already being a `Sql\` concern), with `Cli\Repl` and `Cli\Command\ImportCommand`
updated to the new location. A small, mechanical refactor once a second,
genuinely different consumer existed — the same kind of move this project
made before when `bin/minidb-user`'s logic outgrew standing alone
(Phase 17).

## Recovery replays through the same `Transaction` state machine a live rollback uses

`TransactionManager::recover()` (Phase 8) originally undid a crashed
transaction by filtering its WAL records down to every `INSERT`/`UPDATE`/
`DELETE` and undoing all of them, in reverse — correct for the simple case
that motivated it (a transaction with no `SAVEPOINT` at all), and wrong
for one that isn't: a process that ran `ROLLBACK TO SAVEPOINT` before
crashing has already undone everything logged after that savepoint, both
on disk and in that process's own (now-gone) memory. The WAL still holds
those original mutation records — nothing rewrites history there — so the
old `recover()` tried to undo them a *second* time on restart, and hit
`StorageException: Page 0 slot 1 holds no record`: the row it was trying
to reverse had already been removed once and was simply gone.

This was found writing
[TransactionTest::testARollbackToASavepointBeforeACrashLeavesOnlyThePreSavepointWork](../tests/Integration/TransactionTest.php)
for this milestone — a scenario ("crash immediately after a `ROLLBACK TO
SAVEPOINT`, before the transaction ever reaches `COMMIT`") no earlier
phase's tests happened to construct, since `TransactionManagerTest`'s own
recovery tests (Phase 8) never combine a savepoint with an unfinished
transaction.

The fix: `recover()` no longer filters WAL records by operation type
directly. `TransactionManager::replayStillLiveRecords()` instead feeds a
crashed transaction's records through a fresh `Transaction` object, one
record at a time, calling the exact same methods a live session calls —
`record()` for each mutation, `declareSavepoint()`/`truncateToSavepoint()`/
`releaseSavepoint()` for each savepoint operation — and only then reads
`Transaction::allRecordsReversed()`. This reconstructs precisely the
in-memory undo list the crashed process itself held at the moment it
died, savepoints and all, rather than a naive "everything this
transaction ever touched." A transaction with no savepoints replays to
the same result the old code produced by construction; one with a
`ROLLBACK TO SAVEPOINT` now recovers correctly instead of erroring.

## PHPStan level 8, narrowing not suppression

Raising `phpstan.neon`'s level from 6 to 8 (this milestone) surfaced 165
errors across `src`, `bin`, and `tests` — none were resolved with an
`@phpstan-ignore` comment or a loosened rule; every one is either a real
narrowing at the call site or a docblock correction where the stricter
level was simply more precise than the old annotation:

- **`unpack()`'s `array|false` return**, repeated across ~19 files in both
  `Network\Protocol\*` (wire decoding) and `Schema\Type\*` (disk decoding),
  was the single largest class (48 of the 165 errors). Extracting
  `Support\Binary::unpackInt()`/`unpackFloat()` — narrowing the `false`
  case to a `RuntimeException` once — fixed all of them in one sweep
  rather than repeating the same `if ($value === false) { throw ... }`
  guard at every call site.
- **`fopen('php://memory', 'r+')`'s `resource|false` return**, repeated
  across 7 test files, got the same treatment: `Tests\Support\MemoryStream::memoryStream()`.
- A `list<T>` docblock is stricter than PHPStan can actually prove once a
  method mutates by individual offset rather than only ever appending
  (`Storage\Page::$records`, `Cli\ResultPrinter::tableRow()`'s `$widths`,
  `Transaction\TransactionManager::applyUndo()`'s `$records` parameter,
  `tests/Unit/Network/EventLoopTest.php`'s `$pair` property from
  `stream_socket_pair()`) — relaxed to `array<int, T>` at each of those
  four sites, with a comment saying why the stricter type was correct at
  runtime but unprovable, not a mistake being walked back.
- Everywhere else, a genuinely nullable value (`WalRecord`'s per-operation
  fields, `BTreeIndex::boundary()`'s generic nullability against a
  specific call's always-non-null input, `HeapFile::read()`'s possibly-
  missing record, `$_SERVER['argv']`'s array-vs-list mismatch in
  `bin/minidb`/`bin/minidb-server`) was narrowed with `?? throw` or an
  explicit check immediately before use, matching the pattern
  `Executor::undoInsert()`/`undoDelete()`/`undoUpdate()` already
  established for exactly this shape of problem (Phase 8's WAL record
  fields being nullable at the class level for reasons unrelated to any
  one call site).

## Benchmarks are plain PHPUnit, not a new dependency

`tests/Benchmark/{Insert,Select,Join,Network}Bench.php` (this milestone)
are ordinary `PHPUnit\Framework\TestCase` classes, not PHPBench or a
custom harness — no new `require-dev` dependency, reusing every fixture
helper (`TemporaryDirectory`, `RunningServer`) the rest of the test suite
already has. Each method times itself with `microtime(true)`, prints a
one-line summary to stdout, and asserts only a generous floor (e.g. "at
least 50 rows/s"), not a target — the point is catching an accidental
algorithmic regression (an `O(n²)` where `O(n)` was intended), which a
floor two or three orders of magnitude below realistic hardware still
catches, without the suite flaking on a slower or busier CI machine.

They are excluded from `composer test` (`phpunit.xml`'s `unit` testsuite
excludes `tests/Benchmark`; a second `benchmark` testsuite, matched by a
`Bench.php` suffix instead of the default `Test.php`, is what `composer
bench` runs) so a normal test run stays fast and deterministic; a
benchmark run is opt-in and always a little slower than the code being
measured actually requires, real I/O included.

Profiling via these benchmarks surfaced two things worth naming
concretely rather than only asserting a floor against:

- **Every WAL append `fsync()`s**, autocommit or not (`Transaction\Wal::append()`,
  by design — see "The WAL is logical, and reclaimed only by an explicit
  checkpoint" above). `InsertBench`'s batched-vs-autocommit comparison
  shows this directly: wrapping many inserts in one explicit transaction
  measurably helps (fewer `BEGIN`/`COMMIT` WAL records), but does not
  multiply throughput the way eliminating per-row `fsync()` entirely
  would, because each row's own mutation record is still `fsync()`'d
  individually either way. This is durability bought deliberately, not an
  oversight to fix here.
- **`LEFT`/`RIGHT JOIN` never gets `HashJoin`'s O(n) treatment** —
  `Sql\Optimizer\Rule\JoinReordering` only recognizes a plain-equality
  `INNER JOIN` (see "Join reordering is a two-table swap by page count,
  not a search" above and that rule's own docblock on scope), so every
  outer join runs as `NestedLoopJoin` regardless of shape. `JoinBench`'s
  numbers on a few-thousand-row outer join make the gap visible next to
  the equivalent inner join, but extending hash-join eligibility to outer
  joins is real, separate design work (matching an outer row against "no
  match found" needs bookkeeping a plain hash-join doesn't) that this
  milestone's "optimization" bullet is scoped to *measuring and naming*,
  not building.

## A transaction belongs to its owning connection

An external review of the finished engine raised a concern about
`TransactionManager`'s single-current-transaction design ("Isolation
levels are 2-phase locking, not MVCC" above, and "`Database`, not
`Executor`, owns transaction and lock state"): that a second connection
could act on a transaction it did not open. Checking it against a real
`bin/minidb-server` found the actual bug is worse than "cannot open its
own" (already refused, and already tested — `ServerClientTest::testOnlyOneConnectionAtATimeCanHaveAnOpenTransaction`).
Reproduced directly:

```
A: BEGIN; INSERT id=1 (uncommitted)
B: INSERT id=2 (no BEGIN of its own)   -> SUCCEEDED, silently inside A's transaction
B: COMMIT (never opened one)            -> SUCCEEDED, committing both rows
A: COMMIT                               -> FAILED: "No transaction is active"
```

The root cause: `Executor::withTransaction()` (the autocommit wrapper
around every bare `INSERT`/`UPDATE`/`DELETE`) decided whether to open and
close its own transaction purely from `TransactionManager::inTransaction()`
— true the instant *anything at all* is open, regardless of who opened it.
A bare statement on connection B, running while A's `BEGIN` was still
open, found `inTransaction()` true, treated itself as "not autocommit",
and ran directly inside whatever was current — A's transaction — without
ever calling `begin()`. `commit()`/`rollback()`/`savepoint()`/
`rollbackToSavepoint()`/`releaseSavepoint()` had the same shape of gap one
level up: each only checked *whether* a transaction was open
(`requireCurrent()`), never *who* opened it, so B's explicit `COMMIT`
ended A's transaction outright.

`Network\Session` already had a partial defense —
`$ownsOpenTransaction`, inferred by watching `Executor::inTransaction()`
flip false→true across one statement — but its own docblock's assumption
("a false→true transition can only be this session's own successful
`BEGIN`, since a second one is refused outright while another is already
open") is exactly what the autocommit-join case above breaks: B's
statement causes no flip at all (it was already `true`, from A), so B's
own `$ownsOpenTransaction` correctly stayed `false` — but nothing then
stopped B's *own* later `COMMIT` from succeeding anyway, since that path
never consulted `$ownsOpenTransaction` in the first place. A heuristic
layered on top of the real gap could narrow it, not close it.

The fix adds real ownership to `TransactionManager`: `begin()` takes an
opaque `$owner` token (`Execution\Executor` passes `$this`, compared only
by identity) and remembers it alongside `$current`; `commit()`/
`rollback()`/`savepoint()`/`rollbackToSavepoint()`/`releaseSavepoint()`
all take the same token and refuse (`requireOwnedCurrent()`) if it does
not match. `withTransaction()` checks ownership up front too — `isOwnedBy($this)`
— and rejects a bare statement outright if another connection's
transaction is open, rather than silently joining it. `lockForRead()`
and `currentTransactionId()` switched from `current()` to the new
`currentOwnedBy($this)` for the same reason: a `SELECT` from a connection
that has no transaction of its own must not inherit whatever isolation
level *someone else's* open transaction happens to carry.

This is not a new concurrency model — `Database` is still single-writer,
still exactly one transaction open at a time (see "Isolation levels are
2-phase locking, not MVCC" and "`LockManager` never waits" above); a
second `BEGIN` while one is open is refused exactly as before, by anyone.
What changed is narrower and was the actual bug: only the connection that
opened the one open transaction may act on it, and everyone else's own
bare statements run genuinely autocommit regardless of what someone else
has open. `Network\Session`'s `$ownsOpenTransaction`/`trackTransactionOwnership()`
heuristic is gone — `close()` now asks `Executor::inTransaction()` (now
itself owner-aware) directly, which is both simpler and actually correct.
Proven against a real server in
[ServerClientTest::testASecondConnectionCannotJoinOrCommitAnotherConnectionsOpenTransaction](../tests/Integration/ServerClientTest.php).

## commit()/rollback()/recover() sync storage before their own WAL record

The same external review named a second, independent gap: `Storage\PageManager::write()`
— what every heap and index mutation goes through — only ever `fwrite()`s
a page; `sync()` (`fflush()` + `fsync()`) existed but was called from
nowhere except `Storage\HeapFile::vacuum()`. `TransactionManager::finish()`
(reached by both `commit()` and `rollback()`) checkpointed — truncated —
the WAL immediately after, with nothing in between confirming the pages
that transaction wrote had actually reached the device rather than the
OS's page cache. A crash in that window (checkpoint done, pages not yet
flushed by the OS) would lose data a client had already been told was
committed, with no WAL record left to redo it from — worse than "A row is
mutated before its WAL record is appended" above, since that gap is
narrow and named; this one had no fsync anywhere on the path at all.

Fixed by giving `TransactionManager` the same wiring pattern
`setUndoHandler()` already established for a capability only `Execution\Executor`
has: `setSyncHandler(Closure $handler)` (`Closure(): void`).
`Schema\Database::syncStorage()` is the real handler `Executor`'s
constructor wires in — it walks every currently-open `HeapFile`/`BTreeIndex`
(both gained a `sync()` delegating to their own `PageManager::sync()`) and
syncs each one. Optional, not required like the undo handler: a bare
`TransactionManager` built without a real `Database` behind it (most of
this class's own unit tests) has nothing to sync and no handler to
configure.

**The first version of this fix called `sync()` inside `finish()`, right
before `checkpoint()` — after the `COMMIT`/`ROLLBACK` record was already
appended and `fsync()`'d.** That closed the case a normal `commit()`
actually hits, but left a narrower window open: recovery's *only* signal
that a transaction is finished is a `COMMIT`/`ROLLBACK` record's presence
in the WAL (`recover()`'s own `$isComplete` check) — there is no REDO,
only UNDO for what looks unfinished. A crash between that record's
`fsync()` and `sync()` actually completing would leave the WAL correctly
claiming the transaction finished while the data it covers was still only
`fwrite()`'d, not durable — recovery would see `COMMIT`, skip the
transaction entirely (nothing to undo, as far as it's concerned), and the
data could still be lost. A second review pass, after the first fix, is
what found this — verified by tracing exactly what `recover()` does with
a `COMMIT` record it finds (skips), not merely by re-reading `finish()`.

The actual fix: `commit()`/`rollback()` call `sync()` themselves, *before*
appending their own `COMMIT`/`ROLLBACK` record — `finish()` now only
releases locks and checkpoints, both callers having already made storage
durable by the time they reach it. This makes "a `COMMIT`/`ROLLBACK`
record exists in the WAL" a trustworthy invariant recovery's UNDO-only
design depends on: by construction, that record cannot exist unless the
data it covers already does. `rollback()` also gained a matching case —
its own physical undo writes (`applyUndo()`) need the same treatment
before its `ROLLBACK` record, for the same reason a crash between them
would otherwise leave the WAL claiming "rolled back" while an undone row
was still physically present. `recover()` itself was the third instance
of the same shape: it applies undo writes for every abandoned transaction
and then checkpoints directly, bypassing `finish()` entirely — now it
syncs, once, after every undo and before that checkpoint, for the same
reason. If a sync call throws in any of the three, the corresponding WAL
record — `COMMIT`, `ROLLBACK`, or the checkpoint truncation — never
happens either; the transaction stays exactly as open (or, for `recover()`,
exactly as unrecovered) as it was, for a caller or a later restart to
retry against.

Syncing every open table and index unconditionally, not only the ones the
finishing transaction actually touched, is a deliberate simplification:
tracking a per-transaction dirty set would need `HeapFile`/`BTreeIndex` to
report which pages they wrote, machinery `PageManager` does not have (see
"No buffer pool yet"). At this project's scale — a handful of tables and
indexes open at once, kept open for the life of the process either way —
an `fsync()` on all of them is the simpler, obviously-correct trade, not
a measured bottleneck. `InsertBench`'s numbers before and after this
change are within normal run-to-run variance; the dominant cost was
already the WAL's own per-write `fsync()` (see "Write ordering" in
transactions.md), which this adds to but does not multiply, since
`fsync()` on an unwritten page is cheap and a batched transaction still
pays for a table's pages only once, at its single `commit()`/`rollback()`,
not once per row.

Tested by making the sync handler itself throw and asserting the resulting
WAL never gained the record that would have made it look finished
(`TransactionManagerTest::testTheSyncHandlerRunsBeforeTheCommitRecordIsEvenWritten`/
`...RollbackRecordIsEvenWritten`/`testRecoverySyncsStorageBeforeCheckpointingToo`)
— checking for the *record's absence*, not merely that *something* in the
WAL survived, is what actually distinguishes this from the first,
insufficient version of the fix; the earlier, weaker assertion would have
passed under either ordering.

Still not addressed, and out of scope for a learning project at this
size: recovery's own undo pass is not itself crash-safe (see "Recovery
assumes a single crash" above) — a crash *during* `recover()`'s loop,
between two transactions' undos, is not defended against by this fix
either, only the boundary at the very end of it.

## A failed ROLLBACK stays retryable

Moving the durability barrier in front of the `ROLLBACK` record (above)
created a state that did not exist before it: an undo that has already
been applied, physically, to a transaction that is still open — because
`sync()` threw after `applyUndo()` and the caller is now invited to try
again. Retrying was not safe. `rollback()` read `$tx->allRecordsReversed()`
each time, so the second attempt undid every change a second time, and
undoing an `INSERT` twice means deleting an already-deleted row, which is
a `StorageException`. Reproduced end to end: the first `ROLLBACK` fails
with the sync error, every retry after it fails with `Page 0 slot 1 holds
no record`, and the transaction can then neither commit nor roll back —
permanently stuck, on a connection that did nothing wrong.

`rollback()` now calls `Transaction::forgetAllRecords()` between applying
the undo and the sync, so a retry finds nothing left to undo and gets as
far as the barrier that actually failed. This is what
`rollbackToSavepoint()` has always done — `truncateToSavepoint()` drops
exactly the records it just undid — applied to the whole-transaction
case, which had no equivalent because before the barrier existed nothing
could fail between the undo and the end of the transaction.

The narrower window one level in is still open and still named: if
`applyUndo()` itself throws part-way, the records it already undid are
still in the log, and a retry will undo those again. That is the same
single-failure assumption "Recovery assumes a single crash" already
states, and closing it properly needs undo operations that are idempotent
by construction — a different engine than this one.

## Undoing an already-undone change is success, not an error

Making a failed `ROLLBACK` retryable (above) answered the case where the
caller gets to retry. The reviewer who found that one then asked what
happens when the caller does *not* get to retry — when the process dies
in the same window, between the undo and the `ROLLBACK` record. Checked
rather than assumed, and the answer was worse than a missing guarantee:

```
BEGIN; INSERT; INSERT; ROLLBACK
  -> undo applied, rows gone
  -> sync() throws (full disk), ROLLBACK record never written
  -> process dies
restart
  -> recover() sees a transaction with no COMMIT/ROLLBACK
  -> undoes both INSERTs again, against rows already deleted
  -> StorageException: Page 0 slot 1 holds no record
```

`recover()` runs inside `Execution\Executor`'s constructor, so that
exception is not merely a failed recovery: *every* `Executor` built
against that data directory throws, forever. One full disk plus one crash
left a database that could never be opened again — reproduced end to end
before changing anything.

The fix is the property the reviewer named as the real requirement:
undo is idempotent, so replaying it is harmless. Each of the three asks
whether the change it reverses is already reversed, and returns if it
is — `undoInsert()` when `HeapFile::read()` finds the row already gone,
`undoUpdate()` when the row is gone *or* already holds its `before`
bytes, `undoDelete()` when the row is already back at its own id with
exactly those bytes.

That last one only works because of a second change, which the next
review pass forced. Undoing a `DELETE` used to re-insert the row
wherever `HeapFile::insert()` placed it next, on the reasoning that a
`RecordId` is an address rather than the row's identity. It is both:
undo runs in reverse, so an `UPDATE` logged *before* that `DELETE` in
the same transaction is undone immediately *after* it — and addresses
the row by the id it had. Relocating the row on the way back left that
`UPDATE` undo addressing an empty slot, where the new "already undone"
guard then made it do nothing at all:

```sql
INSERT INTO users VALUES (1, 'Ann'), (2, 'Bea');   -- committed
BEGIN;
  DELETE FROM users WHERE id = 1;   -- frees a lower slot
  UPDATE users SET name = 'Bob' WHERE id = 2;
  DELETE FROM users WHERE id = 2;
ROLLBACK;                            -- left id=2 as 'Bob', not 'Bea'
```

No crash needed — an ordinary `ROLLBACK`, silently wrong. (Before the
guard existed the same case threw instead, which is louder but no more
correct.) `Storage\Page::restore()`/`HeapFile::restore()` now put a
deleted row back at the id it left from, falling back to a plain insert
only if that slot is no longer free — which, with one writer and undo
running in reverse, nothing in this engine currently causes. With the
address preserved, every undo identifies its row the same way, and
`undoDelete()` no longer needs the unique index it briefly used to guess
with (so the "table with no unique constraint" gap that approach left
behind is gone too).

A third pass over the same code found the guards were still reading only
half the picture. An undo writes the heap and then its indexes, with no
barrier between them, so a crash can land there — and a replay that asks
the *heap* whether the work is done then answers "yes" and skips the
index repair. Reconstructed directly, the result is a row that a full
scan finds and an indexed lookup does not, permanently:

```
BEGIN; UPDATE users SET name = 'Bob' WHERE id = 1;   -- name is indexed
ROLLBACK
  -> heap: 'Bob' -> 'Ann'
  -> index: 'Bob' entry removed
  -> crash before the 'Ann' entry is added
restart
  -> recover() replays the undo, sees the heap already holding 'Ann'
  -> returns, index never repaired
SELECT * FROM users WHERE name = 'Ann'   -- [] via the index, one row via a scan
```

So the index half is idempotent now too, not skipped:
`IndexMaintainer::ensureIndexed()`/`ensureNotIndexed()` ask whether this
exact (value, id) pair is present before adding or removing it, and the
undo methods run them unconditionally — only the *heap* write is skipped
when it is already done. `BTreeIndex` itself stays strict (a `delete()`
of a key that is not there is still an error), because outside undo that
strictness is a useful invariant check; it is undo, and only undo, that
legitimately repeats itself.

Two invariants this rests on, worth stating since nothing enforces them
mechanically. `HeapFile::restore()` falls back to a fresh id when the
original slot is no longer free — which within one transaction's
reverse-order undo cannot happen, because anything that took that slot
was written later by the same (single) writer and is therefore undone
first. And recovery undoes one transaction's records as a unit, so no
other transaction's undo interleaves with them.

What this still does not do is add compensation records to the WAL
(ARIES-style CLRs), which is how a production engine makes undo progress
itself durable rather than inferring it from the data. That is a
different WAL protocol, and this project's single-crash model (see
"Recovery assumes a single crash") stays as stated — what has changed is
that a single crash, wherever it lands inside an undo, now leaves
something a replay can finish.
