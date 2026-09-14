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
