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
| Recovery assumes a single crash | current, [why](#recovery-assumes-a-single-crash) |
| `Database`, not `Executor`, owns transaction and lock state | current, [why](#database-not-executor-owns-transaction-and-lock-state) |
| DDL is not transactional | current, [why](#ddl-is-not-transactional) |
| A `LogicalPlan` node carries its own physical decision; there is no separate physical plan | current, [why](#a-logicalplan-node-carries-its-own-physical-decision) |
| A predicate never pushes past an outer join's nullable side | current, [why](#a-predicate-never-pushes-past-an-outer-joins-nullable-side) |
| Join reordering is a two-table swap by page count, not a search | current, [why](#join-reordering-is-a-two-table-swap-by-page-count-not-a-search) |
| A CHECK constraint's text is reparsed on every write, not cached | current, [why](#a-check-constraints-text-is-reparsed-on-every-write-not-cached) |
| `ConstraintEnforcer` answers; `Executor` acts | current, [why](#constraintenforcer-answers-executor-acts) |
| A cascade cycle is not detected | current, [why](#a-cascade-cycle-is-not-detected) |

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
