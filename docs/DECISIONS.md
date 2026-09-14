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
