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
