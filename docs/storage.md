# Storage Layer

This documents the on-disk format implemented under `src/Storage/` and
`src/Schema/Type/` — PLAN.md §6's design, as it actually exists in code.
Everything here is physical: how bytes are laid out on disk and what reads
them back, independent of SQL. `src/Execution/` is what turns a `SELECT`
into calls against these classes; nothing here knows what a `WHERE` clause
is.

## Data directory layout

`Schema\Database::open($dataDirectory)` (via `Storage\Catalog::open()`)
expects, and creates as needed, this layout:

```text
<data>/
  users.json                     -- Network\Auth\UserStore, if auth is used
  wal/
    wal.log                      -- Transaction\Wal, one file for the whole database
  tables/
    <table name>/
      schema.json                -- Storage\TableSchemaCodec's encoding of Schema\Table
      heap.dat                   -- Storage\HeapFile: the table's rows
      <index name>.idx           -- Storage\BTreeIndex: one file per index on this table
```

There is no separate file listing table names — the `tables/` directory
listing *is* the catalog (`Catalog::tableNames()` is a `listDirectory()`
call) — see [DECISIONS.md](DECISIONS.md#no-catalogjson). Dropping a table
removes its whole directory; `CREATE INDEX`/`DROP INDEX` add or remove one
`.idx` file without touching `heap.dat` or the other indexes.

## Pages: the unit everything else is built from

`Storage\Page` is a fixed-size, 8192-byte slotted page — the only place a
record's bytes actually live, whether that record is a table row or a
B-Tree entry:

```text
0 +--------------------------------------+
  | pageId    uint32                     |
4 | type      uint16                     |
6 | slotCount uint16                     |
8 | freeEnd   uint16                     |  where the record area starts
10 +--------------------------------------+
  | slot 0: offset uint16, length uint16 |  directory, grows down
  | slot 1: ...                          |
  +--------------------------------------+
  | free space                           |
  +--------------------------------------+
  | record data, grows up from the end   |
8192 +------------------------------------+
```

The directory grows down from the header and record data grows up from the
end of the page; a page is full exactly when the two meet. A record is
addressed by its **slot number**, not its byte offset — `Storage\RecordId`
is `(pageId, slot)` — which is what lets a page's bytes be rewritten (during
an update, or when a neighbour is deleted) without invalidating anything
that points at the record. See
[DECISIONS.md](DECISIONS.md#slotted-pages-and-addresses-that-survive).

A deleted slot becomes a **tombstone**: `offset = 0, length = 0` in its
directory entry (offset `0` is inside the header and can never be a real
record's start, so no separate flag bit is needed). The directory entry
itself is never removed — every later slot number stays stable — and
`Page::insert()` reuses the first tombstone it finds before appending a new
slot, so a delete/insert cycle does not grow the directory forever.

`Page::MAX_RECORD_SIZE` (`8192 - 10` header `- 4` one directory entry) is
the largest record a page can ever hold; `insert()` throws rather than
silently truncating a larger one — this database has no overflow pages.

In memory a `Page` is held fully decoded (a list of record strings, or
`null` for a tombstone) and only laid out into bytes in `toBytes()`, called
once per write — see
[DECISIONS.md](DECISIONS.md#a-page-is-decoded-in-memory).

`Storage\PageManager` maps a page number to its bytes with pure arithmetic
— page *N* is bytes `[N × 8192, (N + 1) × 8192)` of the file — and keeps no
cache: `read()` decodes a fresh `Page` every call, `write()` re-encodes and
writes one back. There is deliberately no buffer pool; see
[DECISIONS.md](DECISIONS.md#no-buffer-pool-yet).

## `HeapFile`: a table's rows

`Storage\HeapFile` is an unordered sequence of `Storage\Page`s (all
`PageType::HEAP`) holding one table's serialized rows. It knows nothing
about columns or types — a record is just a string, and
`Storage\RecordSerializer` decides what the bytes mean.

- **`insert(string $record): RecordId`** tries the *last* page in the file
  first, allocating a new one only if that page is full — never a page
  earlier in the file with room freed by a delete. See
  [DECISIONS.md](DECISIONS.md#inserts-go-to-the-last-page).
- **`read(RecordId $id): ?string`** returns the record, or `null` if that
  slot has been deleted.
- **`update(RecordId $id, string $record): RecordId`** replaces the record
  in place when it still fits on its page; otherwise it deletes the old
  slot and `insert()`s the new bytes elsewhere. The returned `RecordId` is
  not always the one passed in — a row that grows past what its page can
  hold *moves*, and a caller holding a reference to it (above all, every
  index on the table) has to follow the returned id.
- **`delete(RecordId $id): void`** tombstones the slot; the bytes are not
  reclaimed until `vacuum()`.
- **`scan(): Generator<RecordId, string>`** yields every live record in
  physical (page, then slot) order — what every full table scan (`SeqScan`)
  ultimately calls.
- **`vacuum(): int`** rewrites the whole file with tombstones dropped and
  survivors packed tight, returning bytes reclaimed. **Every `RecordId` in
  the file changes** — anything storing one (every index) must be rebuilt
  afterwards. The rewrite goes to a temporary file renamed over the
  original at the end, so a crash mid-vacuum loses only the temporary.

## Row encoding: `RecordSerializer`

A row — an ordered list of column values, in column-declaration order — is
packed into the bytes a heap slot stores as:

```text
[null bitmap][encoded column values, in order, NULLs skipped]
```

The bitmap is `ceil(columnCount / 8)` bytes; bit *i* (LSB-first within byte
`i >> 3`) is set when column *i* is `NULL`. A `NULL` column contributes
nothing to the value bytes that follow — there is no placeholder to skip,
`deserialize()` simply checks the bitmap before reading the next type's
bytes. This layout deliberately mirrors the wire protocol's row format (see
[docs/protocol.md](protocol.md)), so a decoded heap record needs no
re-encoding of its values to be sent to a client, only a `WireValue` tag
per value.

### Column type encodings

Every `Schema\Type\*` class knows how to `cast()` a raw PHP value into its
canonical in-memory form, and how to `encode()`/`decode()` that form to and
from bytes. All multi-byte integers are big-endian, and every fixed-width
numeric type is encoded in a **sign-bit-flipped, two's-complement ("biased")
form** so that byte-wise comparison (`strcmp()`) orders values the same way
numeric comparison would — the property `Storage\BTreeIndex` depends on for
its keys to sort correctly without decoding them.

| Type | SQL name | Bytes | Canonical PHP value |
|------|----------|-------|----------------------|
| `IntType` | `INT` | 4, biased int32 | `int` (32-bit range) |
| `BigIntType` | `BIGINT` | 8, biased int64 | `int` |
| `BoolType` | `BOOL` | 1 (`0x00`/`0x01`) | `bool` |
| `DecimalType` | `DECIMAL(p, s)` | 8, biased int64 (value × 10^s) | fixed-scale numeric string, e.g. `"123.40"` |
| `VarcharType` | `VARCHAR(n)` | uint32 length + bytes | UTF-8 `string`, ≤ `n` bytes |
| `DateType` | `DATE` | 4, biased int32 (days since epoch) | `DateTimeImmutable` at UTC midnight |
| `DateTimeType` | `DATETIME` | 8, biased int64 (microseconds since epoch) | `DateTimeImmutable`, microsecond precision |
| `BlobType` | `BLOB` | uint32 length + raw bytes | opaque `string`, no UTF-8 requirement |

`DecimalType` stores a **scaled integer**, not a float — `DECIMAL(10,2)`'s
`"123.40"` is the int64 `12340` on disk — which keeps decimal arithmetic
and comparison exact instead of inheriting IEEE-754 rounding; see
[DECISIONS.md](DECISIONS.md#decimal-is-a-scaled-integer-not-a-float).
Precision is capped at 18 digits so the scaled value always fits a 64-bit
PHP int. `VARCHAR`'s declared length caps at 65535 bytes (`VarcharType::MAX_LENGTH`)
— longer text is a `BLOB`, which carries no length limit of its own beyond
what fits on a page. `Schema\Type\TypeFactory::fromName()` parses a type's
*written* SQL form (`"VARCHAR(255)"`, `"DECIMAL(10,2)"`) back into an
instance — round-tripping through the name, not a numeric code, is what
keeps `schema.json` reading as the DDL that produced it; see
[DECISIONS.md](DECISIONS.md#names-rebuild-a-type-codes-only-classify) and
[DECISIONS.md](DECISIONS.md#a-type-name-is-just-a-string-until-the-executor-needs-it).

## `BTreeIndex`: one column, fast lookups

`Storage\BTreeIndex` is a B+Tree over a single column's values, backed by
the same `Storage\Page`/`PageManager` machinery `HeapFile` uses — an
internal or leaf node is just one page (`PageType::BTREE_INTERNAL` /
`BTREE_LEAF`), with the tree's own entry format packed into the bytes a
page slot already frames. See
[DECISIONS.md](DECISIONS.md#a-b-tree-node-is-just-a-page).

- Page `0` of the file is always a `PageType::BTREE_META` page holding the
  current root's page id — the one thing that changes identity as the tree
  grows a level.
- A leaf entry is `[0x01][key bytes]`; an internal entry is
  `[0x01][key bytes][4-byte child page id]`. Each node's first slot is
  reserved for a `0x00`-tagged special entry: the next leaf's page id, for
  a leaf (leaves are linked left to right, for an ordered scan without
  re-descending the tree), or the "less than every real key here" child
  pointer, for an internal node.
- **The stored key is not the indexed value.** It is the value's sortable
  bytes followed by the row's 8-byte `RecordId`. Two rows sharing an
  indexed value (routine for a non-unique index) would otherwise collide
  as literally the same key, which a B+Tree's separators cannot
  distinguish; appending the `RecordId` makes every stored key unique.
  `insert()`/`search()`/`range()` build the right prefix bounds for this
  once; every other internal comparison treats the concatenated bytes as
  an ordinary key via `strcmp()`. See
  [DECISIONS.md](DECISIONS.md#a-stored-key-is-value--recordid-not-just-the-value).
- **`insert(mixed $value, RecordId $id)`** throws
  `Exception\ConstraintViolationException` for a duplicate value on a
  `$unique` index; a `NULL` value is never inserted at all (three-valued
  logic already says `x = NULL` is never true, so indexing it buys
  nothing).
- **`delete(mixed $value, RecordId $id)`** removes the one matching entry
  and does no rebalancing of an underflowed node — the same "space comes
  back at a rebuild, not on every delete" trade `HeapFile` makes; a heavily
  deleted index is rebuilt via `DROP INDEX` + `CREATE INDEX`. See
  [DECISIONS.md](DECISIONS.md#btreeindexdelete-does-not-rebalance).
- **`search(mixed $value)`** and **`range($low, $lowInclusive, $high, $highInclusive)`**
  are both `Generator<RecordId>`s — an equality lookup and an ordered range
  scan, respectively — the two access patterns `Sql\Optimizer\Rule\*`
  chooses `IndexAccess` for over a full `SeqScan`. See
  [DECISIONS.md](DECISIONS.md#a-rule-not-a-planner-for-indexscan).

Only a single-column index is supported — a composite key would need every
component but the last to be either fixed-width or independently
length-framed, or two different multi-column values could concatenate to
the same bytes; deferred rather than approximated. See
[DECISIONS.md](DECISIONS.md#only-single-column-indexes-are-backed). Every
`PRIMARY KEY` and `UNIQUE` constraint gets one of these automatically; see
[DECISIONS.md](DECISIONS.md#primary-keyunique-gets-an-automatic-index).
`DROP INDEX` simply deletes the `.idx` file; see
[DECISIONS.md](DECISIONS.md#drop-index-deletes-the-file).

## `Catalog` and `schema.json`

`Storage\Catalog` reads and writes one `schema.json` per table
(`Storage\TableSchemaCodec`'s encoding of a `Schema\Table`), and is the one
place that validates *across* tables — a foreign key's referenced table
and columns actually exist and are covered by a `PRIMARY KEY` or `UNIQUE`
constraint, and `DROP TABLE` is refused while another table still
references this one. Per-table validation (a column referenced by a
constraint actually exists, a `CHECK` expression only reads this table's
own columns) is `Schema\Table`'s own job, checked before it ever reaches
`Catalog`. See
[DECISIONS.md](DECISIONS.md#table-validates-alone-catalog-validates-across-tables).

A column's type round-trips through its **name** (`"VARCHAR(255)"`), via
`TypeFactory`, and a `DEFAULT` value is stored exactly as given — whatever
JSON-native shape it arrived in, not run through the type's own encoding —
so the file stays plain, inspectable JSON:

```json
{
  "name": "users",
  "columns": [
    {"name": "id", "type": "INT", "notNull": true, "default": null},
    {"name": "email", "type": "VARCHAR(255)", "notNull": true, "default": null}
  ],
  "constraints": [
    {"kind": "primary_key", "columns": ["id"]},
    {"kind": "unique", "columns": ["email"]}
  ],
  "indexes": [
    {"name": "idx_users_email", "column": "email", "unique": true}
  ]
}
```

(Exact key names per `TableSchemaCodec::encodeColumn()`/`encodeConstraint()`/
`encodeIndex()` — this is illustrative of the shape, not a literal dump.)

## What is deliberately not here

- **No buffer pool / page cache.** Every `PageManager::read()` is a fresh
  disk read and decode; `write()` is a fresh encode and disk write. A cache
  would need to track dirty pages and avoid handing the same mutable `Page`
  to two callers who both think they own it — real complexity bought for
  an optimization nothing has yet measured as necessary. See
  [DECISIONS.md](DECISIONS.md#no-buffer-pool-yet).
- **No free-space map.** `HeapFile::insert()` never reuses space a
  `DELETE` freed on an earlier page; only `vacuum()` reclaims it. The
  alternative — tracking each page's free space, updated on every insert
  and delete, kept consistent across a crash — is a second structure this
  project chose not to carry for every phase after the one that would have
  built it.
- **No B-Tree rebalancing on delete**, and **no composite (multi-column)
  indexes** — both named above.
- **No overflow pages.** A record larger than `Page::MAX_RECORD_SIZE`
  (about 8178 bytes) is refused outright, not split across pages.
