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
