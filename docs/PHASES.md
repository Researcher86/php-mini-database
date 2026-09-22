# PHP Mini Database — How It Was Built

This is the running record of the build order for `php-mini-database`. Each
phase is one commit, and a phase is only marked finished after its own
Definition of Done — the tests named below, run with `make test` — passes
together with `make analyse` and `make lint`.

The plan the project is built from lives in [PLAN.md](../PLAN.md). When a
phase is done it is checked off here and on the plan's checklist; design
decisions worth recording move to [DECISIONS.md](DECISIONS.md) as they
surface.

## Phase 0 — Project setup ✅

Scaffold the repository: Composer with PSR-4 autoloading
(`PhpMiniDatabase\` → `src/`, `PhpMiniDatabase\Tests\` → `tests/`), Docker
(PHP 8.5-cli, extensions used across milestones), PHPUnit 11, PHPStan
level 6, PHP CS Fixer, the Makefile, and a package skeleton.

**Done when:** `composer test` runs, `composer analyse` is clean, and
`composer format:check` reports no violations.

**Tests:** `tests/Unit/DatabaseTest.php::testItCanBeInstantiated`.

## Phase 1 — Data types and serialization ✅

The bottom of the stack: what a value *is*, and what it looks like as bytes.

`Schema\Type\Type` is the contract — a type casts a raw PHP value to its
canonical form, packs that form into bytes, and reads it back. Eight
implementations cover the plan's type list: `IntType`, `BigIntType`,
`VarcharType`, `DecimalType`, `BoolType`, `DateType`, `DateTimeType`,
`BlobType`. `TypeFactory` maps a written name (`VARCHAR(255)`) or a wire code
back to an instance, so the catalog and the parser never name classes.

`Storage\RecordSerializer` packs a row as a null bitmap followed by the
non-null values, and `Network\Protocol\ValueCodec` is the network layer's
seam onto the same encoding.

Two decisions here outlive the phase and are recorded in
[DECISIONS.md](DECISIONS.md): the byte format is shared between disk and
wire, and integer-like types are encoded sign-flipped so that byte order is
value order.

**Done when:** `make test`, `make analyse` and `make lint` are all clean.

**Tests:** `tests/Unit/Schema/Type/*Test.php` — one per type, each covering
cast, round trip, byte layout and truncated input;
`tests/Unit/Schema/Type/TypeFactoryTest.php` for the name and code mappings;
`tests/Unit/Storage/RecordSerializerTest.php` for the null bitmap and row
round trip; `tests/Unit/Network/Protocol/ValueCodecTest.php`.

## Phase 2 — Storage engine ✅

Bytes on disk. `Storage\Page` is a fixed 8 KiB slotted page: a header, a slot
directory growing down from the front, record data growing up from the back,
and a tombstone for a deleted slot so that slot numbers — and therefore
record addresses — never shift. `Storage\PageManager` maps page *N* to the
bytes at *N* × 8192 and does nothing else, deliberately: there is no buffer
pool yet, and no caller would change if there were.

`Storage\HeapFile` is the table as a file of records, with `insert`, `read`,
`update`, `delete`, a streaming `scan()` and a `vacuum()` that rewrites the
file without its dead records. `Storage\RecordId` is a row's physical
address, "page:slot".

Underneath, `Infrastructure\` gets the four pieces the rest of the database
will keep reaching for: `FileSystem` (one door to the filesystem, failures as
exceptions, owner-only by default), `AtomicWriter` (temp file, fsync,
rename), `FileLock` (bounded, non-blocking `flock`) and `Path` (identifier
validation and path-traversal refusal).

Five decisions from this phase are in [DECISIONS.md](DECISIONS.md): the
slotted page and stable slot numbers, holding a page decoded in memory, no
buffer pool, inserting only into the last page, and locking a separate file
rather than the data file.

**Done when:** `make test`, `make analyse` and `make lint` are all clean.

**Tests:** `tests/Unit/Storage/PageTest.php` — layout, round trip, tombstone
reuse and four kinds of corrupted page; `PageManagerTest.php` — page
addressing, reopening, out-of-range access; `HeapFileTest.php` — spilling
across pages, the update that moves a record, vacuum;
`RecordIdTest.php`; and `tests/Unit/Infrastructure/*Test.php`, where
`AtomicWriterTest::testAFailedWriteLeavesTheDirectoryUntouched` and
`FileLockTest::testASecondExclusiveLockTimesOut` are the two that matter
most.

## Phase 3 — Schema and catalog ✅

What a table *is*, above the bytes. `Schema\Column` pairs a name with a
`Type`, whether NULL is allowed, and an optional default — kept exactly as
given, cast lazily through the column's own type, so a JSON-native default
round-trips without the schema ever needing to serialize a canonical
`DateTimeImmutable` back into text. `Schema\Constraint\*` is `PrimaryKey`,
`UniqueConstraint`, `ForeignKey` (with `ON DELETE`/`ON UPDATE` actions) and
`CheckConstraint` (expression kept as text — there is no parser yet to hold a
tree). `Schema\IndexDefinition` is kept separate from `UniqueConstraint`:
one says a rule about the data, the other says a structure exists to enforce
it.

`Schema\Table` validates everything decidable from a table alone (no
duplicate or unknown columns, at most one `PRIMARY KEY` and its columns
NOT NULL, every constraint and index naming real columns) and owns the
translation between a `Row` — values by column name — and the positional
bytes `RecordSerializer` deals in, enforcing NOT NULL along the way since
that is the one constraint that needs nothing beyond the row itself.
UNIQUE, FOREIGN KEY and CHECK are left for the executor (Phase 10), which
has the index, the other table and the expression evaluator this layer does
not.

`Storage\TableSchemaCodec` is `Table` in the JSON shape PLAN.md §6.3
describes; `Storage\Catalog` keeps one `schema.json` per table under
`tables/<name>/` and is the layer that checks what a lone `Table` cannot — a
foreign key's other table and columns exist and are actually unique, and a
table is not dropped while another still points at it. `Schema\Database`
is `Catalog` under the public-facing name, waiting for `execute()`/`query()`
to land once the parser and executor exist.

One simplification against PLAN.md's layout is recorded in
[DECISIONS.md](DECISIONS.md): there is no `catalog.json` index file — the
`tables/` directory listing is the catalog, so there is only ever one
place a table's existence is recorded.

**Done when:** `make test`, `make analyse` and `make lint` are all clean.

**Tests:** `tests/Unit/Schema/{Column,Row,IndexDefinition,Table}Test.php` and
`Constraint/*Test.php` for the model and its self-validation;
`tests/Unit/Storage/TableSchemaCodecTest.php` for the JSON shape, including
the DATE-default-as-string case; `tests/Unit/Storage/CatalogTest.php` for
cross-table foreign key validation and the drop-with-dependents refusal;
`tests/Unit/Schema/DatabaseTest.php` for the façade.

## Phase 4 — SQL lexer and parser ✅

Text becomes a tree. `Sql\Lexer` turns SQL source into a flat list of
tokens ending in one `EOF` — the whole list up front, not pulled lazily,
since a statement is at most a few hundred tokens and the parser gets to
look arbitrarily far ahead or backtrack with plain array indexing in
return. `Sql\Parser` is a recursive-descent parser over that list,
producing the tree in `Sql\Ast\`: every DDL statement (`CREATE`/`DROP
TABLE`, `ALTER TABLE ADD/DROP COLUMN`, `CREATE`/`DROP INDEX`, inline and
table-level `PRIMARY KEY`/`UNIQUE`/`FOREIGN KEY`/`CHECK`), every DML
statement (`INSERT` with multiple rows, `UPDATE`, `DELETE`), and `SELECT`
with `JOIN` (inner/left/right, chained and parenthesized), derived tables,
scalar and `IN` subqueries, `GROUP BY`/`HAVING`, `ORDER BY`,
`LIMIT`/`OFFSET`, `DISTINCT`, and the full expression grammar — arithmetic,
comparison, `AND`/`OR`/`NOT`, `LIKE`, `BETWEEN`, `IN`, `IS [NOT] NULL`,
function calls including `COUNT(*)`/`COUNT(DISTINCT x)`, and `?`
placeholders for Phase 14's prepared statements.

The AST is intentionally the one tree everything downstream walks — see
[DECISIONS.md](DECISIONS.md) for why expression nodes live under
`Sql\Ast\Expression\` rather than being re-modelled as separate execution
nodes later, and why `ReferentialAction` is reused directly from
`Schema\Constraint\` instead of the parser inventing its own copy. A type
name (`VARCHAR(255)`) is kept as the string it was written as; turning it
into a `Schema\Type` is deferred to whatever executes a `CREATE TABLE`
(Phase 5), not the parser's job.

Every error — from the lexer or the parser — is a `ParserException`
carrying a 1-based line and column computed from the byte position where it
was raised, the form useful to a human reading the original SQL text.

**Done when:** `make test`, `make analyse` and `make lint` are all clean.

**Tests:** `tests/Unit/Sql/LexerTest.php` for tokenizing, comments,
quoting and escape handling, and four kinds of malformed input;
`ParserSelectTest.php`, `ParserDmlTest.php` and `ParserDdlTest.php` for one
statement kind each, including the plan's own example `CREATE TABLE`;
`ParserExpressionTest.php` for precedence and associativity (multiplication
over addition, `AND` over `OR`, comparisons over `AND`, left-associative
subtraction) and every predicate form; `ParserErrorTest.php` for malformed
statements and the line/column an error reports.

## Phase 5 — Execution engine (basic) ✅

SQL text becomes rows on disk and rows back out again. `Execution\Expression\Evaluator`
walks an AST expression against an `EvaluationContext` — `RowContext` is the
one implementation this phase needs, at most one row and a list of bound
parameters — and implements SQL's three-valued logic properly rather than
approximating it: a `NULL` operand makes arithmetic, comparisons and
`AND`/`OR`/`NOT` evaluate to `null` ("unknown"), following the standard
truth tables, with `IS [NOT] NULL` as the one predicate that always answers
a definite `bool`. A small function registry (`UPPER`, `LOWER`, `LENGTH`,
`ABS`, `CONCAT`, `COALESCE`, `CURRENT_TIMESTAMP`/`CURRENT_DATE`) runs
through an injected `Support\Clock`, so a test can freeze "now".

`Execution\Operator\*` is the read pipeline `SELECT` is built from —
`SeqScan`, `Filter`, `Project`, `Limit`, and `Sort` — each an
`IteratorAggregate` a plain generator satisfies. `Sort` is one operator
beyond Milestone 5's own list, brought forward from Milestone 7 because
`ORDER BY` needs nothing group-by or join related and is central to the
plan's own canonical `SELECT` example (§10.3); `DISTINCT` stayed deferred,
since the plan bundles it with `GROUP BY`/`HAVING` for good reason (it is
group-by-everything-with-no-aggregate). `INSERT`/`UPDATE`/`DELETE`
deliberately do not flow through the operator pipeline — see
[DECISIONS.md](DECISIONS.md) for the Halloween-problem hazard that ruled
that out.

`Execution\TableBuilder` turns a parsed `CREATE TABLE` into a `Schema\Table`
— the first place a column's type name is resolved through `TypeFactory`,
and where `Sql\ExpressionPrinter` (parser's dual, turning an `Expression`
back into reparseable SQL text) supplies `CHECK`'s stored string. A literal
`DEFAULT` is evaluated once; `DEFAULT CURRENT_TIMESTAMP` is refused rather
than silently frozen — recomputing a default per row needs more than
`Schema\Column` can hold yet.

`Execution\Executor` ties it together: `run(sql, parameters)` parses and
executes in one call, `execute()` takes an AST directly. `JOIN`, derived
tables, subqueries, `GROUP BY`/`HAVING`/`DISTINCT`/aggregates, and enforcing
`UNIQUE`/`FOREIGN KEY`/`CHECK` on a write are all refused with a clear
`ExecutionException` rather than mishandled — each is a later phase's job,
named in DECISIONS.md.

**Done when:** `make test`, `make analyse` and `make lint` are all clean.

**Tests:** `tests/Unit/Execution/Expression/EvaluatorTest.php` for the
three-valued truth tables and the function registry;
`tests/Unit/Execution/Operator/*Test.php`, one file per operator, against a
fixed in-memory source so each is tested in isolation;
`tests/Unit/Sql/ExpressionPrinterTest.php` proves the round trip
`print(parse(x))` reparses to an equal tree; `TableBuilderTest.php` for the
AST-to-Schema conversion, including the plan's own example DDL; and
`ExecutorTest.php`, the end-to-end suite — `CREATE TABLE` through a real
`Database`, `INSERT`/`UPDATE`/`DELETE`/`SELECT` against it, and a dedicated
test that an `UPDATE` growing a row past its page does not revisit and
re-apply itself to the moved copy.

## Phase 6 — B-Tree indexes ✅

A second way to find a row: not reading every page, but walking a tree
built to answer one question fast. `Storage\BTreeIndex` is a B+Tree over a
single column, built from the exact same `Storage\Page` a `HeapFile` uses —
an internal or leaf node is one page, a one-byte tag inside each slotted
record distinguishing a real entry from the one reserved slot every node
carries instead of a key (a leaf's link to the next leaf; an internal
node's "less than everything else here" child pointer). Only page 0, a new
`PageType::BTREE_META`, is special: it holds the current root's page id,
the one thing that changes identity as the tree grows a level.

The design detail that took a real, caught-by-a-stress-test bug to get
right: the byte string actually stored and compared is not the indexed
value but `value + RecordId`. Two rows sharing an indexed value — the
normal case for a non-unique index — would otherwise be *the same key
twice*, and a B+Tree's separators cannot represent that; a search
descending past a separator equal to the key it wants has no way to know
matching entries still exist on the other side of it. Appending the
RecordId makes every stored key globally unique, so separators are never
ambiguous; `search()` and `range()` build the right value-prefix bounds
once and everything else just compares whole keys with `strcmp()`. See
[DECISIONS.md](DECISIONS.md) for the full story, including the earlier,
wrong version and how a reproduction script (not just the test suite,
which didn't yet stress duplicate keys hard enough) found it — and for why
that same fix also erased an accidental O(n²) cost that had one stress test
taking 40 seconds.

`Execution\Operator\IndexScan` is `SeqScan`'s counterpart for a query the
index can answer; `Executor` reaches for one, still standing in for
Milestone 9's real planner, when a `WHERE` clause (or the first conjunct of
an `AND`) is a plain `column <op> constant` test against an indexed column
— `Filter` still runs on top regardless, so a wrong or incomplete choice
here is never a correctness risk, only an efficiency one.
`Execution\IndexMaintainer` keeps every single-column index — the explicit
ones from `CREATE INDEX` and the ones `TableBuilder` now backs a
single-column `PRIMARY KEY`/`UNIQUE` constraint with automatically —
in step with `INSERT`/`UPDATE`/`DELETE`, checking uniqueness *before*
writing anything, which is what a `PRIMARY KEY` or `UNIQUE` violation now
means for the first time in this project.

A composite (multi-column) `PRIMARY KEY`, `UNIQUE`, or `CREATE INDEX` is
recorded in the schema but has no backing `BTreeIndex` file yet — a
concatenated multi-column key has its own correctness pitfalls this phase
did not need to take on to deliver single-column indexing, and it is a
named, tested gap rather than a silent one.

**Done when:** `make test`, `make analyse` and `make lint` are all clean.

**Tests:** `tests/Unit/Storage/BTreeIndexTest.php` — including forced
multi-level splits over thousands of entries, and the specific regression
for a duplicate key spanning a leaf split; `tests/Unit/Execution/Operator/IndexScanTest.php`;
`tests/Unit/Execution/IndexMaintainerTest.php` for the uniqueness checks,
including the update-against-its-own-value case; `tests/Unit/Execution/ExecutorIndexTest.php`
for the end-to-end behaviour — `CREATE`/`DROP INDEX`, backfilling an index
over existing rows, a dropped-and-recreated index starting empty rather
than stale, and `SELECT` actually returning the right rows whether or not
an index was available. `benchmarks/index_vs_seqscan.php` measures the
same point lookup through an indexed column against a column with no
index.

## Phase 7 — JOIN, GROUP BY, aggregates ✅

Two-table queries and grouped ones. A joined row is a `Schema\Row` whose
keys are already `"ref.column"` for every column of every table involved —
`Execution\Operator\Qualify` re-keys a plain table scan this way, so
`NestedLoopJoin`/`HashJoin` only ever merge already-qualified rows,
whether a side is a real table or the output of a join one level down.
`Execution\Expression\QualifiedRowContext` is the matching evaluation
context: a qualified lookup is a direct key read, a bare one has to be
unambiguous across every table in scope. `HashJoin` handles the common
`INNER JOIN ... ON a.x = b.y` shape in one pass over each side;
`NestedLoopJoin` is the general fallback — any `ON` expression, and `LEFT`
(with `RIGHT` built by swapping which side is asked for first and
requesting `LEFT`, since the join condition does not care which physical
side a value came from). `Executor` detects which shape applies with the
same small, rule-based approach `IndexScan` selection already established
in Phase 6 — not a planner, just enough to not be always the slow path.

`Execution\Operator\Aggregate` is `GROUP BY` and its functions — `COUNT`,
`SUM`, `AVG`, `MIN`, `MAX` — as one mechanism: every source row is bucketed
by its `GROUP BY` key tuple, then each select-list expression (and
`HAVING`) is evaluated once per bucket by *substituting* every aggregate
call it contains with a `Literal` of that aggregate's value over the
bucket's rows, and running the rewritten, aggregate-free expression through
the ordinary `Evaluator`. That is what lets `HAVING COUNT(*) > 1` — an
aggregate wrapped in a comparison, not a bare aggregate call — work with no
special-casing beyond the substitution itself. `SELECT COUNT(*) FROM t
WHERE false` is one row with `COUNT(*) = 0`; an explicit `GROUP BY` with no
matching rows is zero rows — the same "aggregate over nothing" distinction
real SQL makes. `Execution\Operator\Distinct` runs after projection and
drops a row whose full output tuple already came through once.

`Filter`, `Sort` and `Project` no longer take a fixed table/alias/parameter
triple — they take a `Closure(Row): EvaluationContext`, so the same three
operators serve a plain single-table query (a `RowContext` closure) and a
joined one (a `QualifiedRowContext` closure) without knowing which they are
in. `ORDER BY` runs *before* projection for a plain query, so it can
reference a column that was never selected, and *after* aggregation for a
grouped one, so it can reference an output alias that only exists once
computed — the same operator, two different places in the pipeline,
depending on what it needs to see.

**Done when:** `make test`, `make analyse` and `make lint` are all clean.

**Tests:** one file per new operator (`QualifyTest`, `NestedLoopJoinTest`,
`HashJoinTest`, `DistinctTest`, `AggregateTest` — including the
nested-aggregate substitution and the empty-source-vs-empty-group
distinction) against fixed in-memory sources; `ExecutorJoinTest.php` for
the end-to-end path — inner/left/right, a three-table chain, a non-equi
join still working via `NestedLoopJoin`, and the ambiguous-unqualified-
column error; `ExecutorGroupByTest.php` for `GROUP BY`/`HAVING`/`DISTINCT`
and `ORDER BY` against an aggregate's alias.

## Phase 8 — Transactions and WAL ✅

`BEGIN`/`COMMIT`/`ROLLBACK`/`SAVEPOINT`, a write-ahead log, row/table
locking, and crash recovery — the first phase where a statement's effect
can be undone after it already ran.

`Transaction\Wal` is a *logical* log, not a physical one: one growing file
of JSON-lines records (`{"lsn":1,"tx":1,"op":"BEGIN"}`, per PLAN.md §6.5),
each `append()` `fsync()`'d before it returns. There is no rotation —
space comes back only when `TransactionManager` confirms nothing still
needs the log and calls `checkpoint()` to truncate it outright, the same
"reclaimed only by an explicit action" trade `HeapFile::vacuum()` and
`BTreeIndex`'s lack of rebalancing already made. `Transaction\WalRecord`'s
constructor is private, built only through named factories
(`insert()`/`update()`/`delete()`/`begin()`/…), plus one general
`reconstruct()` `Wal::decodeRecord()` uses to rebuild whatever shape a log
line actually held. A row value that is not natively JSON-safe — a
`DateTimeImmutable`, or a `BLOB` byte string that is not valid UTF-8 — is
wrapped in a small tagged object (`{"__datetime__": "..."}`,
`{"__base64__": "..."}`) on the way out and unwrapped on the way in.

`Transaction\LockManager` is deliberately simple: purely in-memory,
grant-or-throw with no waiting. A refused lock in this engine is always
held by another `Transaction` in the *same* synchronous call stack, which
cannot release it while blocked — real waiting is Phase 12's problem, once
a server gives concurrent sessions something to wait *across*. Isolation
levels are 2-phase locking, not MVCC (there is no buffer pool or row
versioning to base one on): `READ_COMMITTED` holds no read locks at all;
`REPEATABLE_READ` holds a shared lock on every row a `SELECT` visits,
released only at commit/rollback; `SERIALIZABLE` additionally takes a
shared table lock on a full scan, and `INSERT` takes the matching
exclusive table lock before writing — the pair that actually excludes a
phantom row from being inserted while a `SERIALIZABLE` scan is still
using its answer. Read-locking only ever matters once a transaction has a
*later* statement to keep consistent with itself, so it is wired in only
for an explicit, still-open transaction — a bare autocommit `SELECT` never
takes one. All of this is scoped to single-table statements: a `JOIN`'s
rows lose their per-table `RecordId` once `Qualify`/`NestedLoopJoin`/
`HashJoin` merge them, so there is nothing left to attach a row lock to.

Undoing a change is *logical replay* through the same
`Schema\Table`/`Storage\HeapFile`/`Execution\IndexMaintainer` machinery a
fresh `INSERT`/`UPDATE`/`DELETE` uses, not physical page restoration —
`Execution\Executor::undo()` and its three helpers are the other half of
`TransactionManager`'s design: `TransactionManager` holds the *log* of
what happened, but only `Executor` knows how to reverse it against a live
heap and its indexes. `TransactionManager::setUndoHandler()` wires a
`Closure` in after both are constructed, which is what resolves the
circular dependency between them without either depending on the other's
concrete type. Because an `INSERT`'s new `RecordId` (and a moving
`UPDATE`'s post-move one) is not knowable before the heap mutation itself
runs, the WAL entry for a row change is appended — fsync'd — immediately
*after* the mutation, not strictly before it; what actually matters for
recovery, that a transaction's complete log is durable before its
`COMMIT` marker is, still holds. `TransactionManager::recover()` undoes
any transaction left with a `BEGIN` but no matching `COMMIT`/`ROLLBACK`,
in reverse order, and assumes a single point of failure — it does not
defend against a second crash during its own undo pass. Every
`INSERT`/`UPDATE`/`DELETE` now runs inside a transaction either way: the
caller's own, if `BEGIN` opened one, or — via `Executor::withTransaction()`
— one opened and closed around that single statement, which is what gives
a multi-row `INSERT` statement-level atomicity for the first time.

`Wal`, `LockManager` and `TransactionManager` are owned by `Schema\Database`
now, not by `Execution\Executor` — a transaction, and the locks it holds,
are properties of a connection to the database, not of one particular
`Executor` object built to talk to it. Two `Executor`s wrapping the same
`Database` correctly see the same active transaction (and the same
`TransactionException` a second `BEGIN` throws while it is open) instead
of each tracking its own. `TransactionManager::recover()` is a no-op after
its first call for exactly this reason: without that guard, building a
second `Executor` against a `Database` with a transaction already open
would see that transaction's own `BEGIN`-with-no-`COMMIT`-yet on the WAL
and incorrectly undo work that was never abandoned in the first place.

`CREATE`/`DROP TABLE` and `CREATE`/`DROP INDEX` stay outside the WAL and
transaction system entirely, applied immediately regardless of an open
`BEGIN` — the same way many real databases keep DDL non-transactional.

**Done when:** `make test`, `make analyse` and `make lint` are all clean.

**Tests:** `tests/Unit/Transaction/LockManagerTest.php`, `WalTest.php`,
`TransactionTest.php`, `TransactionManagerTest.php` for each piece in
isolation, the last including recovery against a fake undo handler;
`tests/Unit/Sql/ParserTransactionTest.php` for the new grammar; and
`tests/Unit/Execution/ExecutorTransactionTest.php` for the end-to-end
path — commit and rollback of an `INSERT`/`UPDATE`/`DELETE`, a savepoint
partial rollback and a savepoint reused after rolling back to it, a
multi-row `INSERT`'s new atomicity, recovering an incomplete transaction
after reopening the database, DDL running regardless of an open
transaction, and — since two genuinely overlapping transactions cannot
exist through `Executor::run()` alone in this single-session engine yet —
the read/write locking rules exercised directly against the shared
`LockManager` `Database::locks()` now exposes.

## Phase 9 — Planner and Optimizer ✅

A `SELECT` with a `FROM` clause no longer builds its `Operator` pipeline by
hand inside `Execution\Executor`: it goes through `Sql\Planner\Planner`,
which turns the statement into a `LogicalPlan` tree (`Plan\Scan`,
`Plan\Filter`, `Plan\Join`, `Plan\Project`, `Plan\Aggregate`, `Plan\Sort`,
`Plan\Distinct`, `Plan\Limit`), and `Sql\Optimizer\Optimizer`, which
rewrites that tree with a fixed sequence of rules before `Executor::compile()`
turns the result into the same `Execution\Operator\*` classes every earlier
phase already built. This is a direct extraction, not a new execution
model: the tree shape `Planner` builds is exactly the pipeline
`executeSelectFromTable()`/`executeSelectFromJoin()`/`finishSelect()` used
to construct ad hoc, and `compile()` is the mirror image of that — the one
place a plan node ever becomes the operator it describes. There is no
separate `PhysicalPlan` type alongside `LogicalPlan`, unlike PLAN.md's own
file layout: `Plan\Scan`'s `$index` and `Plan\Join`'s `$hash` start `null`
and get filled in by a rule once one applies, so the same node carries a
decision once made rather than a second tree existing just to hold it.

Four rules run in a fixed order — `ConstantFolding`, `PredicatePushdown`,
`IndexSelection`, `JoinReordering` — each doing its own direct recursive
match over the plan tree, the same way `Execution\Expression\Evaluator`
matches `Expression` subtypes directly rather than through a generic
visitor. `ConstantFolding` collapses a `BinaryOp`/`UnaryOp` whose operands
are already literal into one `Literal`, recursing into every other
expression shape's sub-expressions without ever folding a `Placeholder` or
collapsing a function call itself. `PredicatePushdown` moves each
top-level `AND` conjunct of a `WHERE` sitting above a `Join` onto whichever
side alone can answer it — recursing through nested joins, and refusing
outright to push past an outer join's nullable side, since that specific
transformation is not merely narrower but actually unsound (it would
convert an unmatched left row's null-padding into an excluded row, or the
reverse). `IndexSelection` is `Executor::selectSource()`'s exact old rule,
relocated — and, because it runs after `PredicatePushdown`, it now also
applies to a `JOIN` side for the first time, something no phase before this
one did. `JoinReordering` recognizes the same equi-join shape
`Executor::equiJoinKeys()` used to, and additionally swaps which physical
side is `left`/`right` — using each side's `HeapFile::pageCount()` as a
free, already-cached size estimate — so the smaller side always ends up as
`HashJoin`'s hash-built `$right`; it is never applied to an outer join
(swapping those sides would change the query's meaning, not just its
plan), and it is skipped whenever either side is not directly a table
(general N-way join-order search is out of scope, the same kind of
narrowing `IndexScan`/`HashJoin` selection already accepted in Phase 6).

`EXPLAIN <select>` plans and optimizes exactly as a real run would, then
prints the tree instead of compiling it — one line per node
(`LogicalPlan::describe()`), indented under its `children()`. It never
executes the statement it describes.

**Done when:** `make test`, `make analyse` and `make lint` are all clean.

**Tests:** `tests/Unit/Sql/Planner/PlannerTest.php` for the tree shape
every clause combination produces; one file per rule
(`Sql/Optimizer/ConstantFoldingTest.php`, `PredicatePushdownTest.php` —
including the outer-join-nullable-side refusal — `IndexSelectionTest.php`,
`JoinReorderingTest.php` — including a nested join being left alone and an
outer join never being reordered); `tests/Unit/Sql/ParserExplainTest.php`
for the grammar; and `tests/Unit/Execution/ExecutorExplainTest.php` for
the end-to-end path, including that `EXPLAIN` never mutates anything.

## Phase 10 — Integrity Constraints ✅

`FOREIGN KEY` and `CHECK` are enforced on a write for the first time —
`NOT NULL`, `UNIQUE` and `PRIMARY KEY` already were, from Phase 3 (`Schema\Table`)
and Phase 6 (`Execution\IndexMaintainer`) onward, and the DDL-time cross-
table validation a `FOREIGN KEY` needs (its referenced table and columns
exist, and are covered by a `PRIMARY KEY`/`UNIQUE` constraint) has been
`Storage\Catalog`'s job since Phase 3 too. What was still missing was
checking either constraint against an actual row at `INSERT`/`UPDATE`
time, and — for `FOREIGN KEY` specifically — deciding what happens to a
child row when the parent it points at is deleted or its key changes.

`Execution\ConstraintEnforcer` is the first half: `assertCheckConstraints()`
parses a `CheckConstraint`'s stored text back into an `Expression` (via
the new `Sql\Parser::parseExpression()`, the reverse of the round trip
`Sql\ExpressionPrinter` already made for storing it) and evaluates it —
rejecting a row only when the result is a definite `false`
(`Execution\Expression\Evaluator::isFalse()`, the CHECK-shaped counterpart
to the WHERE-shaped `isTrue()`; a `NULL` result, e.g. a comparison against
a `NULL` column, passes, matching standard SQL). `assertForeignKeysOnWrite()`
checks the *referencing* side of a `FOREIGN KEY`: any row with a `NULL` in
one of its foreign key columns is exempt (MATCH SIMPLE), and everything
else must match a row in the referenced table — via that table's
`BTreeIndex` when the key is single-column, or a full scan when it is not
(composite keys still have no index to look them up in — the same gap
Phase 6 already named).

The *referenced* side is `Execution\Executor`'s own job instead:
`cascadeBeforeDelete()`/`cascadeBeforeUpdate()` search every other table
for a `FOREIGN KEY` pointing at the one about to be written to, and, for
each row that would dangle, either refuse the write (`RESTRICT`/
`NO_ACTION`), physically delete or update the child row to match
(`CASCADE`), or null its foreign key columns (`SET NULL` — which fails on
its own, for free, if those columns are `NOT NULL`, since it goes through
the same `Table::valuesFromRow()` every other write does). Both run
*before* the parent row's own write is applied, and `CASCADE` recurses
through `cascadeBeforeDelete()` again for each child, so a grandchild
table cascades too. `physicallyDeleteRow()`/`physicallyUpdateRow()` are
the heap/index/WAL steps `executeDelete()`/`executeUpdate()` used to do
inline, extracted so a cascaded child row goes through exactly the same
write path a top-level one does — logged, locked, and undoable by
`TransactionManager` identically.

**Done when:** `make test`, `make analyse` and `make lint` are all clean.

**Tests:** `tests/Unit/Execution/ConstraintEnforcerTest.php` for `CHECK`
(true/false/null) and `FOREIGN KEY` (matched/unmatched/null-exempt, and
the composite-key full-scan fallback) in isolation; and
`tests/Unit/Execution/ExecutorConstraintTest.php` for the end-to-end
path — `INSERT`/`UPDATE` rejected by either constraint (including a
multi-row `INSERT` left entirely untouched by a failure partway through),
`RESTRICT` refusing a delete or a key change with a referencing child,
an unrelated column update never even checking for children, `CASCADE`
recursing through a grandchild table, `SET NULL` (and its own failure
against a `NOT NULL` column), and a self-referencing foreign key
cascading within one table.

## Phase 11 — TCP Protocol ✅

The binary wire protocol PLAN.md §5 designs, implemented and tested end to
end — with no socket anywhere in it yet. `Network\Protocol\Frame` is the
fixed envelope (magic, version, type, flags, a length-prefixed payload);
`Network\Protocol\FrameReader` reassembles whole frames out of a byte
stream that may deliver one in pieces or several at once, which is what
lets a real socket's `fread()` output feed it directly once Milestone 12
gives it one. `Network\Protocol\MessageType` is one `int`-backed enum for
every row of PLAN.md §5.3's table — merged with what that table's own file
layout calls `Opcode`, since nothing in the protocol needs the two ideas
kept apart (see DECISIONS.md). Every message type has its own class under
`Network\Protocol\Message\`, each carrying its own fields and its own
`payload()`/`fromPayload()`; `Network\Protocol\Codec` is the seam between
one of those and a `Frame`, dispatching by `MessageType` to decode the
right one, the same way `Sql\Parser::statement()` dispatches on a
`TokenType`.

`Network\Protocol\WireValue` is what a `QUERY`'s bound parameters and a
`QUERY_RESULT`'s row values are both encoded as: a value prefixed with a
one-byte tag naming its own shape (`NULL`/`BOOL`/`INT`/`FLOAT`/`STRING`/
`DATETIME`), rather than trusting a declared `Schema\Type` neither one
reliably has — a bound parameter has no column yet to get a type from, and
a `SELECT` output column is just as often a computed value as a stored
one. `DECIMAL` and `BLOB` values need no tag of their own (already plain
strings by the time anything sees them); `DATE` and `DATETIME` share the
one `DATETIME` tag. This is also why `Network\Protocol\Message\QueryResultMessage`
drops PLAN.md §5.6's per-column `type_code`/`flags` and its row-level null
bitmap: `Execution\QueryResult` never had a `Schema\Type` per column to
report, and a `WireValue`'s own tag already says `NULL` without a separate
bitmap. `Network\Protocol\ResultEncoder` turns `Execution\Executor::execute()`'s
three possible answers — a `QueryResult`, an `int` row count, or `null` —
into that one message shape, and turns any exception into a
`Network\Protocol\Message\QueryError` via `Network\Protocol\ErrorCode`
(PLAN.md §5.7/§19's table, plus one addition, `EXECUTION_ERROR`, for the
shape of failure `Exception\ExecutionException` covers that none of the
original twelve codes fit).

**Done when:** `make test`, `make analyse` and `make lint` are all clean.

**Tests:** `tests/Unit/Network/Protocol/FrameTest.php` (round trip, bad
magic, truncated header/payload, trailing bytes, unknown type, an
oversized payload refused at construction); `FrameReaderTest.php` (a frame
split across many feeds, several frames in one feed, a corrupted magic
mid-stream); `WireValueTest.php` (every tag round-tripping, including
`PHP_INT_MIN`/`PHP_INT_MAX` and a non-UTF-8 string, plus truncated/unknown-tag
buffers); `CodecTest.php` (every message type round-tripping through
`Codec`, including `BEGIN` with each isolation level and a `QUERY_ERROR`
carrying context); and `ResultEncoderTest.php` (all three `Executor::execute()`
result shapes, and each mapped exception type).

## Phase 12 — TCP Server (Core) ✅

The protocol Phase 11 defined now has a real server behind it — the
plainest one PLAN.md §2.2 allows: a single-process, single-threaded event
loop (`Network\EventLoop`) rather than forking, chosen precisely because
forking is the weaker of the two options that same line names ("Windows
with fork limitations"), and because one process serving every connection
keeps `Schema\Database`'s single-writer state in one place with no shared
memory needed between workers. `EventLoop::tick()` — one `stream_select()`
call plus whatever callbacks it makes ready — is the primitive everything
else is built from: `run()` is `while (!stopped) tick()`, and every test
in `tests/Unit/Network/ServerTest.php` drives that exact same method by
hand, over a real TCP socket bound to an OS-assigned port, with no second
process or thread anywhere.

`Network\Acceptor` owns the listening socket; `Network\Session` owns one
client connection — decoding frames via `Network\Protocol\Codec`, running
`Query` messages through its own `Execution\Executor`, and answering with
whatever `Network\Protocol\ResultEncoder` turns the result or the
exception into. `Network\Server` wires them together with a
`Network\SessionManager` (which enforces `ServerConfig::$maxConnections`)
around one shared `Schema\Database` — so two sessions really do share the
single-writer transaction state Phase 8 designed for, provable for the
first time with two real sockets instead of two `Executor`s in one test
method (see DECISIONS.md).

Authentication does not exist yet — `HELLO` is answered with `HelloAck`
immediately, "dev mode" exactly as PLAN.md §11 names it — so `Session`
accepts a `Query` right after the handshake with no `Auth` exchange in
between. Every other message type Phase 11 already defined but this phase
has no handler for (`Auth`, `Prepare`/`Execute`, the typed `Begin`/`Commit`/
`Rollback`/`Savepoint`) closes the connection rather than being silently
ignored — `BEGIN`/`COMMIT`/`ROLLBACK`/`SAVEPOINT` already work today
regardless, as plain SQL text through `Query`, since `Execution\Executor`
has parsed and run them since Phase 8.

Graceful shutdown is `pcntl_signal()` on `SIGTERM`/`SIGINT`, installed
only by `Server::run()` — never by `start()` alone — so a test that only
ever calls `tick()` by hand never touches process-wide signal state.
`Server::shutdown()` (closing every session, the listening socket, and
the `Database`) is exposed directly for exactly that case: a test's
`tearDown()` calls it without ever having called `run()`.
`Infrastructure\Logger` is one concrete class, not an interface with
implementations — `$path === null` discards everything, which is what
every test that needs a `Server` but not its log output constructs,
without a second `NullLogger` file that would only ever do nothing.
`bin/minidb-server` is the minimal real entrypoint PLAN.md §7.2's
environment variables drive — checked by hand against an actually
running, separate process, not only against PHPUnit driving ticks in the
same one.

**Done when:** `make test`, `make analyse` and `make lint` are all clean.

**Tests:** `tests/Unit/Network/EventLoopTest.php` (a callback firing on
readability, `removeReadable()`, a callback safely removing another
stream's watch within the same batch, `run()` stopping once `stop()` is
called); `tests/Unit/Infrastructure/LoggerTest.php`; and
`ServerTest.php` for the end-to-end path — connect and handshake, a
`SELECT`/DDL/DML/bound-parameter round trip, a failing query answered
with `QueryError`, `PING`/`PONG`, `GOODBYE` and an abrupt disconnect both
noticed server-side, a connection over `max_connections` refused, a
`QUERY` before the handshake closing the connection, and two real
sessions where the second's `BEGIN` is refused while the first's
transaction is still open.

## Phase 13 — Authentication ✅

`HELLO`/`HELLO_ACK`/`AUTH`/`AUTH_OK`/`AUTH_FAIL` all do real work now —
Milestone 12's "dev mode" (`HELLO` answered immediately, `Query` accepted
with no `Auth` exchange) stays available as `ServerConfig::$authEnabled`'s
default, but a server can now require a real login.

`Network\Auth\PasswordHash` derives a user's stored secret with
`sodium_crypto_pwhash()` (Argon2id) in its *raw-output* form, not
`password_hash()`/`password_verify()` — the challenge-response scheme
PLAN.md §5.5 describes needs both the client and the server to
independently compute `HMAC(hash, nonce)` and compare, which needs the raw
hash bytes as a key, not a one-way "yes/no" `password_verify()` can never
give back. The salt that goes into that derivation is not a random value
stored alongside the hash, as PLAN.md §6.4's `users.json` example shows,
but `SHA-256(username)` — deterministic, so nothing has to be sent to (or
looked up by) a client before it knows which user is authenticating, which
resolves a real gap in PLAN.md §5.4's own four-message handshake sequence:
`HELLO_ACK` happens before the server has any idea which user is
connecting, so it cannot hand back *that* user's salt at that point. See
DECISIONS.md for the full reasoning and what it costs. `HelloAck::$salt`
is renamed to `$nonce` to match what the field actually carries once this
phase gave it a job: a fresh, per-connection random value for the HMAC,
never a salt.

`Network\Auth\ScramChallenge` is the shared authority for the HMAC itself
(`nonce()`, `respond()`, `verify()` via `hash_equals()`) — used by the
server to check a login, and by this phase's own tests to compute a
correct response, standing in for the real client library Milestone 16
still has to build. `Network\Auth\UserStore` persists every user's hash
and roles to `users.json` via the same `Infrastructure\AtomicWriter`
`Storage\Catalog` already uses, for the same reason: a half-written
credentials file after a crash is a real outage. `Network\Auth\LoginThrottle`
is PLAN.md §13.3/§15's "rate limiting + backoff" — in-memory, per
username, doubling the lockout after `$maxAttempts` free failures, capped
— checked by `Network\Auth\Authenticator::verify()` before anything else,
and given the same generic failure reason as a wrong password or an
unknown username, so neither ever tells an attacker which one it was.

`Network\Session` grows a `handleAuth()` case alongside its existing
`Hello`/`Query`/`Ping`/`Goodbye` ones; `Query` before a successful `Auth`
(when one is required) is refused the same way an unsupported message is,
but a *failed* `Auth` leaves the connection open rather than closing it —
a client that mistyped a password gets to retry with a new `Auth` message,
which is exactly what `Authenticator`'s throttling (not the connection
itself) is what eventually stops. `bin/minidb-user` is the CLI PLAN.md
§9.2 shows (`add`/`remove`/`list`) — a local tool editing `users.json`
directly, not a `bin/minidb` client command, since no client library
exists yet for the real one PLAN.md reserves that name for to connect
through.

Two things fixed in passing, not new to this phase: `bin/minidb-server`
(Phase 12) had never actually been checked by `composer analyse` or
`composer format:check` at all — both tools' directory scans silently
skip an extensionless file, and nobody had told either one to look at it
by name specifically. Both configs now do, and `composer format` found
(and fixed) one real, previously invisible formatting mistake in that
file the moment it started actually being checked.

**Done when:** `make test`, `make analyse` and `make lint` are all clean.

**Tests:** `tests/Unit/Network/Auth/` — `PasswordHashTest.php`,
`ScramChallengeTest.php` (correct/wrong hash/nonce/response),
`UserStoreTest.php` (create/find/remove/list, persistence across a fresh
instance), `LoginThrottleTest.php` (free attempts, lockout, exponential
backoff, the cap, a lock expiring once enough fake time has passed, reset
on success, usernames throttled independently), `AuthenticatorTest.php`;
and `tests/Unit/Network/ServerAuthTest.php` for the end-to-end path —
`HELLO_ACK` offering a nonce, a `Query` before authenticating refused,
correct and wrong credentials, a failed attempt not closing the
connection and a retry succeeding, enough failures locking the account
out even against the right password, and dev mode still working
unauthenticated when `authEnabled` is left off.

## Phase 14 — Prepared Statements ✅

`Network\Session` answers `PREPARE`, `EXECUTE` and `CLOSE_STMT` instead of
closing the connection on them, the last three `Message` types Phase 11
defined but Phase 12 left unhandled (besides the typed transaction control
messages, still Milestone 15's job).

`PREPARE` parses `$sql` once with `Sql\Parser::parseOne()` and keeps the
resulting `Sql\Ast\Statement` in a per-session `array<int, Statement>`
keyed by a `uint32` id that starts at `1` for every new connection;
`PREPARE_OK` hands that id back, or a `QueryError` reports a syntax error
the same way `Query` already does. `EXECUTE` looks the id up and passes it
straight to `Execution\Executor::execute()` — the same method `Query`'s
`run()` already calls after its own `parseOne()` — so a prepared statement
is genuinely parsed only once no matter how many times it runs; an unknown
or already-closed id is a `QueryError`, not a crash. `CLOSE_STMT` just
`unset()`s the entry: PLAN.md's message table gives it no reply, and
closing an id twice (or one that was never open) is treated as the normal
case for a fire-and-forget release message, not an error.

`ServerConfig::$maxPreparedStatements` (default `100`, matching PLAN.md
§7.1's example) caps how many statements one session may hold open at
once; `PREPARE` past the cap answers with a `QueryError` instead of a new
id, exactly like a parse failure — there is no dedicated "PREPARE failed"
message any more than there is a dedicated "EXECUTE failed" one.

The SQL-injection protection this milestone asks for was not new machinery
to build: a bound parameter has always been a typed `WireValue`, decoded
into a plain value and bound by the existing placeholder handling, never
text the parser ever sees — `PREPARE`/`EXECUTE` only gives that existing
guarantee a second entry point. `ServerPreparedStatementTest` proves it
directly, executing an `INSERT` with a parameter value crafted to look
like a second SQL statement and confirming it lands in the table as one
literal string, not as executed SQL.

**Done when:** `make test`, `make analyse` and `make lint` are all clean.

**Tests:** `tests/Unit/Network/ServerPreparedStatementTest.php` — a
prepared `SELECT` executed twice with different parameters, a prepared
`INSERT` executed multiple times, a malicious parameter value proven to
land as a literal rather than as SQL, a `PREPARE` with invalid SQL, an
`EXECUTE` against an unknown or already-closed statement id, closing an
unknown id being silently harmless, the per-session limit being enforced
and freed up again by a `CLOSE_STMT`, and `PREPARE` before the handshake
being refused like any other message.

## Phase 15 — Transactions over the Network ✅

`BEGIN`, `COMMIT`, `ROLLBACK` and `SAVEPOINT` now work as typed wire
messages, not only as plain SQL text through `Query`: `Network\Session`'s
new `handleBegin()`/`handleCommit()`/`handleRollback()`/`handleSavepoint()`
each build the matching `Sql\Ast\*Statement` by hand and hand it to
`Execution\Executor::execute()` — the exact same method `Query` and
`EXECUTE` already call — rather than teaching `Executor` a second way to
run a transaction-control statement. `ROLLBACK TO SAVEPOINT` and `RELEASE
SAVEPOINT` get no typed message of their own, matching PLAN.md §5.3's
message table exactly (it defines none for either) — both stay reachable
only as plain SQL text, unchanged from before this phase.

The real work this phase adds is what "handling timeouts and disconnects"
actually requires: `Schema\Database` allows only one open transaction at a
time, system-wide (Phase 8's single-writer model), so a session that opens
one and then disconnects without `COMMIT`/`ROLLBACK` would otherwise leave
it open forever — locking every other session out of `BEGIN` permanently,
not just inconveniencing the one client that vanished. `Session` now
tracks `$ownsOpenTransaction`, set by watching its own statement flip the
new `Executor::inTransaction()` from `false` to `true` (a false→true
transition can only be this session's own successful `BEGIN`, since a
second one is refused outright while one is already open) and cleared the
same way on the way back to `false`; `close()` rolls back first when this
flag is set. This works identically whichever path opened the
transaction — a typed `Begin` or a plain-SQL `Query('BEGIN')` — since both
reach `Executor` the same way. Full idle/query-timeout *detection* stays
the pre-existing named gap `ServerConfig::$idleTimeoutSeconds`/
`$queryTimeoutSeconds` already documented (Phase 12) — `EventLoop` still
has no per-socket elapsed-time tracking — but whenever that gap is closed,
a timed-out socket closing is just another disconnect this phase's cleanup
already covers.

Two boxes fixed in passing, not new to this phase: PLAN.md §2.1's
"Transactions" functional-requirement checklist (`BEGIN`/`COMMIT`/`ROLLBACK`,
`SAVEPOINT`/`ROLLBACK TO SAVEPOINT`, isolation levels, ACID via WAL and the
lock manager) had stayed unchecked since Phase 8 actually built all four,
apparently missed at the time; ticked now since re-reading the code
confirmed all four are true and already tested.

**Done when:** `make test`, `make analyse` and `make lint` are all clean.

**Tests:** `tests/Unit/Network/ServerTransactionTest.php` — `BEGIN`/
`COMMIT`/`ROLLBACK` round-tripping over the wire, a `ROLLBACK` undoing
everything since `BEGIN`, an explicit isolation level on `BEGIN`, `SAVEPOINT`
combined with a plain-SQL `ROLLBACK TO SAVEPOINT`, a second `BEGIN` while
one is open and a `COMMIT` with none open both reported as `QueryError`,
a session disconnecting mid-transaction being rolled back and freeing the
single writer for a fresh connection, a session disconnecting *outside*
any transaction leaving another session's open one untouched, and `BEGIN`
before the handshake refused like any other message.

## Phase 16 — Client Library ✅

A real PHP client for this project's own protocol: `Client\Connection`
(`connect()`/`query()`/`execute()`/`prepare()`/`beginTransaction()`/`commit()`/
`rollback()`/`savepoint()`/`isAlive()`/`close()`/`reconnect()`), `Client\Statement`
(a `PREPARE`d handle), `Client\ResultSet` (a fetched result, `fetch()`/
`fetchAll()`/`foreach`-able/`Countable`), `Client\ClientConfig` and
`Client\ClientException` — PLAN.md §4's file layout and §8.2's usage
example, now real code instead of an illustration.

`Connection` is a thin, blocking-looking wrapper over the same wire this
project's server side already speaks: every public method sends one
`Message` and waits for exactly one reply. The socket itself is
non-blocking, and every wait goes through `stream_select()` — the same
primitive `Network\EventLoop` already uses server-side — rather than
`stream_set_timeout()`, because that call shares one timeout between reads
and writes and could not give `ClientConfig`'s three separate timeouts
(`connect`/`read`/`write`) real, independent budgets. Auth reuses
`Network\Auth\PasswordHash`/`ScramChallenge` directly rather than
reimplementing the challenge-response math a second time — exactly what
their own docblocks (written back in Phase 13) already earmarked them for.

`execute()` is not a second wire request: every `Query` answers with the
same `QUERY_RESULT` regardless of statement kind, so `execute()` just
calls `query()` and reads `ResultSet::affectedRows()` off the
`affected_rows`-column convention `Network\Protocol\ResultEncoder`'s own
docblock already spelled out for exactly this. `ConnectionPool` is
"test on borrow", not "test on return": `release()` returns a connection
to the idle list unconditionally, and `acquire()` is the one place that
spends a `PING`/`PONG` round trip checking `isAlive()`, reconnecting in
place via `Connection::reconnect()` if not — satisfying PLAN.md's
"Reconnect on failure" without ever silently retrying a request that may
have already reached the server once.

The bigger decision this phase made was how to *test* a library whose
whole API is one blocking call per request: `Network\Server`'s own tests
(`ServerTest` and its siblings) drive `Server::tick()` by hand from the
test method itself, which only works because the test is also the thing
making the client calls. `Client\Connection` end to end needs something
actually running concurrently to answer it, so `tests/Support/RunningServer`
launches the real `bin/minidb-server` as a genuine child process via
`proc_open()` — a new testing pattern for this project, and a more
faithful one for this specific layer: it is the actual thing a `Connection`
is built to talk to, not a stand-in for it. See DECISIONS.md.

**Done when:** `make test`, `make analyse` and `make lint` are all clean.

**Tests:** `tests/Unit/Client/ResultSetTest.php` (no sockets — `fetch()`'s
own cursor vs. `foreach`/`fetchAll()` not sharing it, the `affected_rows`
convention, an empty DDL-shaped result); `tests/Unit/Client/ConnectionTest.php`,
`ConnectionAuthTest.php` and `ConnectionPoolTest.php` (a real
`bin/minidb-server` child process per test, via `RunningServer`) covering
connect, `query()`/`execute()`, bound parameters, a `QueryError` arriving
as a `ClientException` carrying its `ErrorCode`, prepare/execute/close,
`BEGIN`/`SAVEPOINT`/`ROLLBACK`/`COMMIT` round-tripping through the client,
`close()`/`reconnect()`/`isAlive()`, a closed port failing immediately,
correct and wrong credentials against a real challenge-response handshake,
and the pool's exhaustion, reuse, dead-connection reconnect, and `close()`
behavior; `tests/Unit/Client/ConnectionTimeoutTest.php` (a bare, silent
listening socket, no real server needed) for the read timeout actually
firing. The write timeout is implemented the same way as the read timeout
but is not independently exercised — reliably forcing a loopback socket's
write to block needs its kernel send buffer full, which this suite does
not attempt to simulate; see DECISIONS.md.

## Phase 17 — CLI Client and REPL ✅

`bin/minidb` — PLAN.md §9.2/§9.3's `connect`, `query`, `shell`, `import`,
`export`, `user` subcommands are real; `backup`/`restore` are recognized
subcommands that print an honest "not implemented yet, see Milestone 19"
rather than improvising a backup format the plan gives a later milestone
to design. `bin/minidb-user` (Phase 13) is retired: its logic now lives
in `Cli\Command\UserCommand`, reached as `bin/minidb user ...` — the
single entrypoint PLAN.md's file layout always showed, `bin/minidb-user`
having only existed because `bin/minidb` itself did not yet.

`Cli\ArgvParser` splits `argv` into a command, positional args, and
`--name value` options (repeatable, e.g. `--table users --table posts`),
generalized from `bin/minidb-user`'s own parser to also support
value-less boolean flags (`--json`, `--quiet`) from an explicit list,
rather than guessing from context. `Cli\OutputFormat` picks table (the
default)/json/csv/vertical from those flags; `Cli\ResultPrinter` renders
each, plus the `N row(s) in set|affected (X sec)` status line every
command prints unless `--quiet`.

`import`/`export` are scoped to what the protocol can actually support:
there is no `SHOW TABLES` (no SQL statement, no wire message), so
`export` cannot discover a database's tables on its own — every table
has to be named with `--table`, repeatable — and there is no way to ask
for a table's schema either, so only `INSERT` statements are written,
data without `CREATE TABLE`. `Cli\SqlSplitter` (reusing `Sql\Lexer::tokenize()`,
already quote- and comment-aware) is what lets `import` and `Cli\Repl`
both split a dump file or a REPL line into individual statements, since
`Message\Query` only ever carries one at a time.

`Cli\Repl` reads a line at a time, buffering across lines until tokenizing
what has been typed so far both succeeds and ends in a `;` — an
unterminated string or an unfinished `CREATE TABLE` reads as "keep going",
not an error, the same as a real SQL shell's multi-line input. History
and autocompletion come from `ext-readline` when present (a graceful
fallback to plain `fgets(STDIN)` otherwise); completion is keyword-only,
seeded from `Sql\TokenType::keywords()` — the same reason `export` cannot
discover tables applies to completing their names too.

Fixing a real bug found while testing: `Client\Connection::query()` on an
already-closed connection previously crashed with an uncaught `TypeError`
from `stream_select()` instead of a clean `ClientException` — `send()`/
`receive()` now check `$closed` first (`requireOpen()`), and
`reconnect()`'s internal ordering had to change to flip `$closed` back to
`false` *before* calling `handshake()` rather than after, to avoid the
same new guard blocking its own reconnection attempt.

Two boxes fixed in passing, not new to this phase: PLAN.md §2.1's CLI
checklist had `minidb-server` unchecked despite it working since Phase 12;
and `bin/minidb-server`'s own docblock cited "Milestone 17" as the future
home of its `--host`/`--port`/`--config` flag parsing, which actually
belongs to Milestone 18's PID-file/start-stop-status-reload lifecycle —
corrected, and left untouched otherwise, since retrofitting flags with no
lifecycle around them yet would be premature.

**Done when:** `make test`, `make analyse` and `make lint` are all clean.

**Tests:** `tests/Unit/Cli/SqlSplitterTest.php`, `ArgvParserTest.php`,
`OutputFormatTest.php`, `ResultPrinterTest.php` (all pure logic, no
sockets); `tests/Unit/Cli/ClientApplicationTest.php` and `ReplTest.php`
against a real `bin/minidb-server` child process via `RunningServer` —
every subcommand, a round trip through `export` then `import`, an
import stopping at its first failing statement, a REPL statement spanning
several lines, two statements on one line, a failing statement not
ending the session, and a lost connection ending it with a nonzero exit.

## Phase 18 — Server Administration ✅

Two mostly-independent halves: `SHOW_STATUS`/`SHOW_CONNECTIONS`/`KILL`
over the wire, and `bin/minidb-server`'s own `start`/`stop`/`status`/
`reload` process lifecycle.

`Network\Metrics` is one instance shared across every `Session` (owned by
`Server`, injected the way `SessionManager` already is) — cumulative
counters for connections, queries and errors, plus a plain average
queries-per-second since the process started. `SHOW_STATUS`/
`SHOW_CONNECTIONS`/`KILL` (`Network\Session::handleShowStatus()`/
`handleShowConnections()`/`handleKill()`) all answer with a
`QUERY_RESULT`, the same convention every prior phase's new response
shape has reused rather than inventing a message type for. `Session`
gained `$username` (set on a successful `AUTH`, `null` forever in dev
mode) and `$connectedAt` for `SHOW_CONNECTIONS`' rows, and a reference to
both `SessionManager` and `Metrics` to read from and act on. There is no
SQL grammar for any of this — `Sql\Lexer`/`Sql\Parser` were never taught
`SHOW`/`KILL` — only the typed wire messages Phase 11 already defined;
`Client\Connection` exposes them directly, `bin/minidb` gets `status`/
`connections`/`kill <id>` as real subcommands, and `Cli\Repl` recognizes
PLAN.md §10.4's plain text (`Cli\AdminCommand`) and translates it to the
same typed calls, so the interactive shell still looks like the plan's
own illustration without the parser gaining new grammar for
administration commands alone. See DECISIONS.md.

A real bug surfaced building `KILL`: one session closing a *different*
session's socket (`SessionManager::find()` plus that session's own
`close()`) left `Network\EventLoop` still watching the now-closed
resource, since only the session whose own callback is running got swept
from the loop before this phase. The next `tick()`'s `stream_select()`
would be handed an already-`fclose()`d resource and throw a `TypeError`
rather than fail gracefully. `Server::serviceSession()` now sweeps every
session for `isClosed()` after any one callback runs, not only the one
whose callback it was — the same class of sharp edge Phase 17's
`Client\Connection::requireOpen()` already guards against on the client
side.

`bin/minidb-server` gets real subcommands (`Cli\ServerApplication`/
`Cli\Command\ServeCommand`, matching PLAN.md §4's file layout, which
names only `ServeCommand` — `stop`/`status`/`reload` stay inline the same
way `connect` did in `Cli\ClientApplication`). `start` reads `--host`/
`--port`/`--data`/`--max-connections`/`--auth-enabled`/`--log-level`/
`--log-file` (CLI flag, then the matching `MINIDB_*` variable, then a
default — PLAN.md §7.3's precedence minus the `config/server.php` layer,
which no milestone's checklist actually claims building; see
DECISIONS.md), plus `--pid-file` and `--daemon` for the process
lifecycle. `Cli\PidFile` is what `start` writes and `stop`/`status`/
`reload` read back — none of the three ever open a connection to the
server they are talking about, since a pid and a signal are all any of
them need. `start` refuses outright if the pid file already names a
running process, and recovers cleanly from a stale one (the process
behind it is gone). `--daemon` forks (`pcntl_fork()`), the parent exits
immediately, and the child calls `posix_setsid()` and closes `STDIN`/
`STDOUT`/`STDERR` — the standard minimal Unix daemonizing recipe — but
refuses to daemonize at all if `--log-file`/`MINIDB_LOG_FILE` still names
a terminal stream, since a detached process can never be told what it
tried to log there again.

`SIGHUP` (`reload`) is honestly scoped to the one thing that is actually
reloadable without a config file: `Network\SessionManager::$maxConnections`,
re-read from `MINIDB_MAX_CONNECTIONS`. `$host`/`$port` are the listening
socket and `$dataDirectory` is the one `Schema\Database` already open —
neither can change without restarting something, and nothing else in
`ServerConfig` has anywhere to reload *from* yet.

**Done when:** `make test`, `make analyse` and `make lint` are all clean.

**Tests:** `tests/Unit/Network/MetricsTest.php` (a `Tests\Support\FakeClock`,
no sockets); `tests/Unit/Network/ServerAdminTest.php` — `SHOW_STATUS`'s
counters after real queries and a real error, `SHOW_CONNECTIONS` listing
every open session, `KILL` actually closing its target and freeing the
`EventLoop`'s watch on it, killing an unknown id as a `QueryError`,
admin commands before the handshake refused like any other message, and
`Server::reload()` raising the connection limit live; `tests/Unit/Cli/AdminCommandTest.php`
(pure text-parsing, no sockets); `tests/Unit/Cli/ServerApplicationTest.php` —
real child processes for `start`/`stop`/`status`/`reload`/`--daemon`,
including the already-running refusal, stale-pid recovery, the daemon
requiring a real log file, and the daemon actually detaching while the
server keeps answering queries; extended `ClientApplicationTest.php`/
`ReplTest.php` for `status`/`connections`/`kill` and the REPL's plain-text
recognition of them.

Two boxes ticked in passing, not new to this phase: PLAN.md §2.1's CLI
checklist had `minidb-server` unchecked despite working since Phase 12,
and `EXPLAIN` unchecked despite working since Phase 9 — both re-verified
against the current code and ticked now.

## Phase 19 — Backup, Dump, Restore ✅

Two independent pairs, both under `src/Backup/`, matching PLAN.md §4's
exact class names: `Dumper`/`Restorer` (a logical, SQL-level backup) and
`BackupManager` (a physical, tar.gz one). Neither talks to the network —
"works via server and embedded" turned out to mean the mechanism itself
is embedded (`Dumper`/`Restorer` read `Schema\Database` directly, `BackupManager`
archives a data directory's files directly), reachable either from a
user's own PHP script or from `bin/minidb-server`'s own CLI, not that
either works over `Client\Connection` too. This is exactly what lets
`Dumper` do the one thing `Cli\Command\ExportCommand` (Phase 17,
network-mode) cannot: discover every table on its own
(`Schema\Database::tableNames()`) and emit `CREATE TABLE` alongside
`INSERT`, not only data. See DECISIONS.md.

`Dumper` orders tables by foreign-key dependency (parents before
children) on a best-effort basis — a cycle, or a reference outside the
tables being dumped, falls back to whatever order it was given rather
than looping forever, the same honesty `Execution\Executor`'s own cascade
handling already has about not detecting a cycle (Phase 10).
`Table::indexes()` includes the index a `PRIMARY KEY`/`UNIQUE` constraint
automatically gets alongside any genuinely separate one — re-emitting
those as `CREATE INDEX` would create the same index twice on restore, so
`Dumper` filters them out by matching names against the constraint that
spawned them. `Backup\SqlLiteral` (a plain value → SQL literal formatter)
is shared with `Cli\Command\ExportCommand`, which used to have its own
private copy of exactly the same logic. `Sql\StatementSplitter` — what
`Restorer` needs to split a dump into individual statements, the same way
`Cli\Command\ImportCommand`/`Cli\Repl` already do — moved from
`Cli\SqlSplitter` once a second, non-CLI consumer existed; a `Cli\`-only
name no longer fit.

`BackupManager` uses `PharData` (`ext-phar`) for the tar.gz archive
itself — no shelling out to a `tar` binary — verified directly to work
regardless of `phar.readonly` (that ini setting only restricts
*executable* `.phar` files). `backup()` writes beside the requested path
and `rename()`s into place last, the same atomicity `Infrastructure\AtomicWriter`
already uses for the same reason. `restore()` refuses a non-empty target
directory unless told `$force`, so a caller cannot accidentally mix two
databases' files together in one directory.

`bin/minidb-server` gains `dump`/`load` (the SQL pair, avoiding a name
collision with the tar.gz pair's own `restore`) and `bin/minidb`'s
`backup`/`restore` — recognized-but-stubbed since Phase 17 — are real now,
using `BackupManager`. Both stay local filesystem operations, the same
category `user add/remove/list` already is: a caller with visibility into
a data directory runs them directly, no `Client\Connection` involved.

A real, if narrow, bug surfaced while testing `BackupManager`: a brand
new data directory with no tables yet (`Database::open()` then `close()`,
nothing ever created) makes `PharData::buildFromDirectory()` add zero
entries, and `PharData` then silently never writes the `.tar.gz` file to
disk at all — no exception, just a missing file `backup()`'s own
`unlink()`/`rename()` calls would then fail against. Fixed by adding one
harmless placeholder entry when the built archive is empty; the restored
copy loses that placeholder-shaped directory structure on extraction, but
`Database::open()` recreates whatever it needs from nothing regardless,
so nothing real is lost.

**Done when:** `make test`, `make analyse` and `make lint` are all clean.

**Tests:** `tests/Unit/Backup/DumperTest.php` (column/constraint/index
rendering, dependency ordering, an explicit table filter, a table with no
rows); `RestorerTest.php` (schema + data restored, comments skipped, the
first failing statement stopping the rest); `BackupManagerTest.php` (a
real round trip, the missing-source/missing-archive errors, the
empty-database edge case, the non-empty-target refusal and its `$force`
override); `SqlLiteralTest.php`; `DumpRestoreRoundTripTest.php` — the
"Roundtrip tests" PLAN.md's own checklist names explicitly, a schema
using every constraint kind `Dumper` renders, restored and then proven to
still *enforce* its `CHECK` and cascade its `FOREIGN KEY`, not just parse
back as matching text; `tests/Unit/Cli/ServerApplicationDumpTest.php`
(`dump`/`load` in process — neither touches a socket or a signal) and
extended `ClientApplicationTest.php` for the now-real `backup`/`restore`.

## Phase 20 — Testing, Optimization, Documentation ✅

The last milestone, and the only one whose checklist has no single class
to build — eight bullets covering coverage, integration/concurrency/load
testing, `PHPStan` strictness, profiling, and the six documentation files
PLAN.md's file tree always named but nothing had written yet. Tackled in
that rough order, each verified independently rather than assumed done
once the code compiled.

**PHPStan level 8** (`phpstan.neon`, was level 6) came first as the most
mechanically checkable piece: 165 errors across `src`/`bin`/`tests`, none
resolved by suppressing or loosening a rule (see
[DECISIONS.md](DECISIONS.md#phpstan-level-8-narrowing-not-suppression)).
The single largest class — `unpack()`'s `array|false` return, repeated
across 19 files — came down in one sweep by extracting `Support\Binary`;
everything else was a real narrowing at the call site (`?? throw`, an
`assertNotNull`/`assertInstanceOf` in a test, a `list<T>` docblock
relaxed to `array<int, T>` where a method's actual guarantee was weaker
than the annotation claimed).

**Integration tests** (`tests/Integration/`) deliberately avoid
duplicating what `tests/Unit/Execution/*` already proves feature by
feature. `SqlEndToEndTest`/`ConstraintTest` run larger, realistic,
multi-feature scenarios against one embedded `Database` (a small blog
schema, a cascading delete that is itself blocked one level further down,
a multi-row insert failing partway through) rather than isolating one
constraint or clause at a time. `TransactionTest` does what no unit test
does — closes a `Database` mid-transaction (no `COMMIT`/`ROLLBACK` ever
called) and opens a fresh one against the same directory, proving
`Executor`'s constructor-time recovery actually undoes an abandoned
transaction and leaves a committed one alone, across real process-lifetime
boundaries. `ServerClientTest` and `AuthTest` run against a real
`bin/minidb-server` child process (`Tests\Support\RunningServer`) with
several independent `Client\Connection`s at once, and `AuthTest` creates
its user through `bin/minidb user add` itself (`Cli\Command\UserCommand`),
not `UserStore` constructed by hand — the actual path a deployment uses.
`ConcurrencyTest` uses `pcntl_fork()` for genuine OS-level concurrent
clients (six real child processes writing 25 rows each into one shared
table) rather than several `Connection`s taking turns in one test process,
proving no row is lost or duplicated under real simultaneous writers.

Two of `ServerClientTest`'s tests were written expecting standard
snapshot-isolation semantics and had to be corrected against what this
engine actually does: there is no MVCC (see
[DECISIONS.md](DECISIONS.md#isolation-levels-are-2-phase-locking-not-mvcc)),
so an uncommitted `READ_COMMITTED` write is visible to every other
connection immediately, not hidden until commit, and `TransactionManager`
allows only one open transaction *per `Database`*, not per connection —
a second `BEGIN` elsewhere is refused before either session ever reaches
a row-level lock conflict. Both are now assertions about the real,
documented behavior rather than a false expectation the tests happened to
pass by accident.

**A real bug** surfaced writing `TransactionTest`'s savepoint-then-crash
case: `TransactionManager::recover()` undid every mutation a crashed
transaction had ever logged, without regard for a `ROLLBACK TO SAVEPOINT`
that had already undone some of them live — a double-undo that threw
`StorageException: Page 0 slot 1 holds no record` on restart. Fixed by
replaying a crashed transaction's WAL records through the same
`Transaction` state machine a live rollback uses, reconstructing exactly
the still-live undo list the crashed process held rather than assuming
"every mutation this transaction ever logged." See
[DECISIONS.md](DECISIONS.md#recovery-replays-through-the-same-transaction-state-machine-a-live-rollback-uses)
for the full root cause.

**Benchmarks** (`tests/Benchmark/{Insert,Select,Join,Network}Bench.php`)
are plain PHPUnit, in a `benchmark` testsuite `composer test` excludes and
`composer bench` runs on its own — no new dependency, no change to CI
speed. Profiling through them surfaced two characteristics worth naming
rather than silently living with: every WAL append `fsync()`s regardless
of autocommit (durability bought deliberately, not an oversight), and
`LEFT`/`RIGHT JOIN` never gets `HashJoin`'s treatment since
`JoinReordering` only recognizes a plain-equality `INNER JOIN` — both
detailed in
[DECISIONS.md](DECISIONS.md#benchmarks-are-plain-phpunit-not-a-new-dependency).

**Documentation**: `docs/sql.md`, `architecture.md`, `storage.md`,
`transactions.md`, `security.md`, `cli.md` — `docs/protocol.md` already
existed and set the house style (real class names, honest about gaps,
cross-linked to `DECISIONS.md` rather than re-explaining rationale
inline) that all six now follow. `storage.md`, `security.md`, and `cli.md`
were drafted by independent research passes, then verified line by line
against the actual source and the `DECISIONS.md` anchors they cite before
being accepted — one genuinely wrong claim (RIGHT JOIN having no compiled
path, when `Executor::compileJoin()` actually reuses LEFT JOIN's handling
by swapping sides) was caught this way before publishing.

**Examples** (`examples/{embedded,client,pool,transaction}.php`) are
runnable, not illustrative snippets — each was executed against a real
`bin/minidb-server` (or, for `embedded.php`, a real on-disk database) and
made idempotent (safe to run twice in a row against the same data) before
being accepted; `client.php`'s first draft used `COALESCE(MAX(id), 0)`,
which this engine's planner does not support (an aggregate nested inside
another function call), caught by actually running it rather than reading
it back.

**Done when:** `composer test`, `composer analyse` (now genuinely level
8) and `composer format:check` are all clean; coverage is 90.5% lines
(`Xdebug`, `--coverage-text`) — above the 85% target without any test
added purely to move the number.

**Tests:** `tests/Integration/{SqlEndToEndTest,ConstraintTest,TransactionTest,ServerClientTest,AuthTest,ConcurrencyTest}.php`;
`tests/Benchmark/{InsertBench,SelectBench,JoinBench,NetworkBench}.php`;
plus every PHPStan-level-8 narrowing fix across `tests/Unit/*` (no test's
assertions changed, only what the type checker can prove about them).

## Post-plan: two transaction-safety gaps found in review

All 20 milestones done, but the plan never had a milestone for "have
someone else read the finished thing" — an external review of the engine
raised two concerns about `Transaction\*`, checked against the real code
rather than taken at face value (one claim from the same review, about
`BTreeIndex` range boundaries for `VARCHAR`, turned out not to be a
correctness bug on inspection — `IndexSelection`'s `Filter` re-validates
every row an `IndexScan` returns, proven by actually running the query).

The two that held up were real, and worse in practice than first
described. A second `Network\Session` sharing the same `Schema\Database`
as one with an open transaction could not only fail to open its *own*
`BEGIN` (already true, already tested) but could have a bare statement of
its own run silently *inside* the first session's transaction, and could
`COMMIT`/`ROLLBACK` it outright — reproduced directly against a real
`bin/minidb-server`, not just read off the code. Separately,
`Storage\PageManager::write()` never `fsync()`'d a page on the ordinary
write path, and `TransactionManager::finish()` checkpointed (truncated)
the WAL right after a `COMMIT` regardless — a crash in that window could
lose data a client had already been told was committed.

Both fixed: `TransactionManager` now tracks which caller's `begin()`
opened the current transaction and refuses everyone else
(`commit()`/`rollback()`/`savepoint()`/an autocommit statement of their
own), replacing a `Network\Session` heuristic (`$ownsOpenTransaction`,
inferred by watching a flag flip) that turned out not to actually catch
the bug it was built for. `Schema\Database::syncStorage()` is wired in as
a new `setSyncHandler()` capability, run before every checkpoint. See
[DECISIONS.md](DECISIONS.md#a-transaction-belongs-to-its-owning-connection)
and
[DECISIONS.md](DECISIONS.md#commitrollback-sync-storage-before-checkpointing-the-wal)
for both in full, including why neither changes this engine's
single-writer model.

**Tests:** `TransactionManagerTest`'s new "Ownership" and "Sync handler"
sections (the latter proven by making the sync handler itself throw, and
asserting the WAL survives — the only way that can pass is if `sync()`
genuinely runs before `checkpoint()`); `ServerClientTest::testASecondConnectionCannotJoinOrCommitAnotherConnectionsOpenTransaction`
against a real server.
