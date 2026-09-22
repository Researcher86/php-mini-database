# Architecture

How a SQL statement gets from text to a result, and how the pieces under
`src/` are layered to do it. Each layer only talks to the one below it;
nothing here skips a layer for convenience. See
[sql.md](sql.md)/[storage.md](storage.md)/[transactions.md](transactions.md)/
[protocol.md](protocol.md)/[security.md](security.md)/[cli.md](cli.md) for
each layer's own detail — this page is the map between them.

## The layers

```
Cli\ClientApplication / ServerApplication      (bin/minidb, bin/minidb-server)
        |
Client\Connection / ConnectionPool             (PLAN.md §8.2/§8.3, over TCP)
        |
Network\Protocol\*  (wire frames/messages)      Network\Server / Session      (Milestone 12)
        |                                               |
        +-----------------------------------------------+
                                |
                        Execution\Executor
                       /        |         \
              Sql\Parser   Sql\Planner   Execution\Operator\*, Expression\*
             (text -> AST)  (AST -> plan   (plan -> running pipeline)
                             -> Sql\Optimizer\Optimizer)
                                |
        Schema\Database  --  Transaction\TransactionManager / LockManager / Wal
                |
        Storage\Catalog, HeapFile, BTreeIndex, Page, RecordSerializer
                |
                         the data directory on disk
```

`Backup\Dumper`/`Restorer`/`BackupManager` sit beside `Executor` (PLAN.md
§11): a dump runs ordinary `SELECT`s through it and a restore runs ordinary
DDL/DML back through it, rather than reading/writing storage files
directly — see [DECISIONS.md](DECISIONS.md) for why.

## Two ways in, one engine underneath

`Execution\Executor` is the one place SQL text becomes a result — both
entry points reach the exact same class, not two parallel implementations:

- **Embedded**: `Schema\Database::open($dataDirectory)` +
  `new Execution\Executor($database)`, in the same PHP process, no network
  — [examples/embedded.php](../examples/embedded.php).
- **Client-server**: `Network\Session` (one per accepted connection) holds
  its own `Executor` against the server's single `Database`, and
  `Client\Connection` is a wire-protocol client that turns method calls
  into `Query`/`Execute` messages and back — [examples/client.php](../examples/client.php).

`Executor`'s constructor does one more thing worth knowing up front: it
calls `TransactionManager::recover()` unconditionally, replaying any
transaction a previous process left open in the write-ahead log. See
[transactions.md](transactions.md#crash-recovery).

## `Sql\Parser` → `Sql\Planner` → `Sql\Optimizer` → `Execution\Operator`

1. **`Sql\Parser::parseOne()`** turns SQL text into an AST
   (`Sql\Ast\SelectStatement`, `InsertStatement`, …) via a straightforward
   recursive-descent/precedence-climbing parser — no intermediate token
   stream saved anywhere; `Sql\Token`/`TokenType` are consumed as they are
   produced.
2. **`Sql\Planner\Planner`** turns one `SELECT`'s AST into a tree of
   `Sql\Planner\Plan\*` nodes (`Scan`, `Filter`, `Join`, `Aggregate`, `Sort`,
   `Project`, `Distinct`, `Limit`) — a *logical* plan: what has to happen,
   not yet how. `Planner::tail()`'s own docblock is the authoritative source
   for the fixed node order (`WHERE`, then `GROUP BY`/`HAVING`/aggregate or
   a plain projection, then `DISTINCT`, then `LIMIT`).
3. **`Sql\Optimizer\Optimizer`** rewrites that logical plan in place —
   currently one rule, `Rule\JoinReordering`, which recognises an `INNER
   JOIN` with a plain equality `ON` and marks it `HashJoinKeys`-eligible
   (see that rule's own docblock for exactly which shapes qualify, and why
   a `LEFT`/`RIGHT JOIN` is out of scope for it).
4. **`Executor::compile()`** turns the (possibly rewritten) logical plan
   into a live `Execution\Operator\*` pipeline — `SeqScan`/`IndexScan`,
   `Filter`, `NestedLoopJoin`/`HashJoin`, `Aggregate`, `Sort`, `Project`,
   `Distinct`, `Limit` — each a `Traversable` that pulls from the one below
   it, so a query never materialises more rows in memory than its slowest
   stage needs to. `Execution\Expression\Evaluator` is what every operator
   that needs to check a `WHERE`/`ON`/`HAVING`/select-item expression calls
   into, against an `Expression\RowContext`/`QualifiedRowContext` (plain vs.
   join-qualified column resolution — see `Executor::compile()`'s own
   docblock on `$isJoin` for why the pipeline threads a flag for this
   rather than detecting it dynamically per row).

`EXPLAIN SELECT ...` (PLAN.md §7.6) runs steps 1–3 and prints the resulting
plan tree without step 4 ever executing it.

## Mutations, constraints, and indexes

`INSERT`/`UPDATE`/`DELETE` do not go through the `Plan\*`/`Operator\*`
pipeline above — that pipeline is for producing rows, and a write is a
single, direct path in `Executor`:

1. `Execution\ConstraintEnforcer` checks `NOT NULL`/`CHECK`/`UNIQUE`/
   `PRIMARY KEY`/foreign keys against the write's new (and, for `UPDATE`/
   `DELETE`, old) row values — before anything touches disk. A violation
   throws `Exception\ConstraintViolationException` and nothing is written.
2. The row is written to `Storage\HeapFile` (and `Execution\IndexMaintainer`
   updates every `Storage\BTreeIndex` on the table to match) — see
   [storage.md](storage.md).
3. Only now does `Transaction\TransactionManager::logInsert()`/`logUpdate()`/
   `logDelete()` append the change to the WAL — physical mutation first,
   log second; see [transactions.md](transactions.md#write-ordering) for
   why, and what it costs.

## `Schema\Database`: one object per data directory

`Schema\Database` owns everything scoped to one on-disk database: the
`Storage\Catalog`, every open `Storage\HeapFile`/`BTreeIndex` (opened once,
kept open, closed together by `Database::close()`), the
`Transaction\Wal`, and — because a "transaction" and a "lock table" are
properties of a connection to *this* database, not of any one `Executor`
built to talk to it — the single `Transaction\LockManager` and
`Transaction\TransactionManager` every `Executor` against it shares. This
is also why only one transaction can ever be open against a given
`Database` at a time server-wide; see
[transactions.md](transactions.md#one-transaction-at-a-time).

## The server: one process, an event loop, many sessions

`Network\Server` accepts connections on `Network\Acceptor` and drives every
open `Network\Session` from one `Network\EventLoop::tick()` — a single PHP
process, no threads, no forked workers (PLAN.md §12's own scope). Each
`Session` wraps one client's `Executor`, `Network\Protocol\FrameReader`, and
authentication state; `Network\SessionManager` is the registry `SHOW
CONNECTIONS`/`KILL` (PLAN.md §10.4) walk. Because every session's
`Executor` shares the same `Database` (and therefore the same single
`TransactionManager`), a `BEGIN` on one connection while another already
has one open is refused server-wide, not just for that row — see
[tests/Integration/ServerClientTest.php](../tests/Integration/ServerClientTest.php)
for this proven end to end, and [DECISIONS.md](DECISIONS.md) for the
`LockManager`-never-waits reasoning this follows from.

## Where each PLAN.md milestone landed

| PLAN.md area | Namespace |
|---|---|
| §2 Storage engine | `Storage\*` |
| §3 SQL parser | `Sql\Ast\*`, `Sql\Parser`, `Sql\Token*` |
| §4 Schema & types | `Schema\*` (except `Schema\Row`, which is `Execution`'s row shape) |
| §6/§7 Query execution | `Sql\Planner\*`, `Sql\Optimizer\*`, `Execution\*` |
| §8 Transactions | `Transaction\*` |
| §5/§9 Wire protocol | `Network\Protocol\*` |
| §10 TCP server | `Network\*` (minus `Protocol`) |
| §8.2/§8.3 Client library | `Client\*` |
| §13 Auth | `Network\Auth\*` |
| §9 CLI | `Cli\*`, `bin/minidb*` |
| §11 Backup | `Backup\*` |
| Cross-cutting | `Infrastructure\*` (files, locks, logging), `Support\*` (small shared helpers), `Exception\*` (one exception type per failure category, thrown across every layer above) |
