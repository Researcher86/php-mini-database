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
(`MiniDatabase\` → `src/`, `MiniDatabase\Tests\` → `tests/`), Docker
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
