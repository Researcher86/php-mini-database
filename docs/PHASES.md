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
