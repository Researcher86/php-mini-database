# PHP Mini Database

> A small, readable relational database written in PHP — a laboratory for
> learning how databases actually work: on-disk storage, file formats,
> records and pages, indexes, SQL, query execution, transactions, WAL, crash
> recovery, locking, and concurrency.

A **PHP runtime engineering playground**: one small, readable implementation
of every mechanism a database server is built from, so that any one of them
can be opened and understood on its own.

The goal is not to compete with MySQL, PostgreSQL or SQLite. It is to have
somewhere to look when you need to remember how a storage engine, an index,
or a query planner actually works — with a version small enough to read in
one sitting and real enough to run and benchmark.

The implementation plan lives in [PLAN.md](PLAN.md) and is executed phase by
phase; each phase is a single commit.

---

## Status

**All 20 milestones of [PLAN.md](PLAN.md) are done.** Latest finished
phase: **Phase 20 — Testing, Optimization, Documentation**. PHPStan runs
at level 8 (`phpstan.neon`) across `src`, `bin`, `tests`, and `examples`
with zero errors; line coverage is 90.5%
(`Xdebug`, `--coverage-text`). `tests/Integration/` covers realistic,
multi-feature scenarios end to end — embedded and client-server, crash
recovery across real process restarts, authentication through the actual
`bin/minidb user` CLI, and genuinely concurrent clients via `pcntl_fork()`
— on top of the feature-by-feature `tests/Unit/` suite every earlier
phase built. `tests/Benchmark/` (`make bench`, excluded from `make test`)
measures insert/select/join throughput and network round-trip latency; a
real crash-recovery bug involving `ROLLBACK TO SAVEPOINT` was found and
fixed writing this milestone's tests (see
[docs/DECISIONS.md](docs/DECISIONS.md#recovery-replays-through-the-same-transaction-state-machine-a-live-rollback-uses)).
`docs/{sql,architecture,storage,transactions,security,cli}.md` now cover
every layer alongside the wire protocol (`docs/protocol.md`, written
earlier); `examples/{embedded,client,pool,transaction}.php` are runnable,
verified end to end against a real server, not illustrative snippets.

Since then, an external review of the finished engine surfaced two real
transaction-safety gaps — a second connection could silently join and
even `COMMIT` another connection's open transaction, and `COMMIT` did not
wait for a transaction's pages to actually reach disk before discarding
the WAL record that could have redone them — both reproduced against a
real server and fixed; see
[docs/PHASES.md](docs/PHASES.md#post-plan-two-transaction-safety-gaps-found-in-review).

The phase-by-phase record of the build is in [docs/PHASES.md](docs/PHASES.md),
and the reasoning behind the designs that survived is in
[docs/DECISIONS.md](docs/DECISIONS.md).

## Getting started

Requires Docker. Nothing is installed on your machine.

```bash
make install    # build the image and install dependencies
make test       # run the test suite
make bench      # run the (slow, opt-in) benchmark suite
make analyse    # run PHPStan
make lint       # check formatting
make fix        # apply PHP CS Fixer
```

```bash
make shell      # drop into the container
```

## Documentation

- [docs/sql.md](docs/sql.md) — the SQL dialect: statements, expressions, types, constraints
- [docs/architecture.md](docs/architecture.md) — how the layers fit together, text-in to rows-out
- [docs/storage.md](docs/storage.md) — the on-disk format: pages, heap files, B-tree indexes, the catalog
- [docs/transactions.md](docs/transactions.md) — isolation levels, locking, the WAL, crash recovery
- [docs/protocol.md](docs/protocol.md) — the binary wire protocol
- [docs/security.md](docs/security.md) — authentication, what is and isn't protected
- [docs/cli.md](docs/cli.md) — `bin/minidb-server` and `bin/minidb` reference

## Layout

```
├── bin/            CLI entry points (minidb, minidb-server)
├── benchmarks/     standalone, manually-run measurement scripts
├── docs/           decisions, phase history, and the reference docs above
├── examples/       runnable scripts (embedded, client, pool, transaction)
├── src/            the database itself (Sql, Execution, Storage, Transaction, Network, Client, Cli, ...)
├── tests/
│   ├── Unit/       one feature or class at a time
│   ├── Integration/  multi-feature scenarios, embedded and client-server
│   ├── Benchmark/  PHPUnit-driven throughput/latency timing (`make bench`, not `make test`)
│   └── Support/    shared test fixtures and helpers
└── var/data/       on-disk data files (gitignored content, kept as a dir)
```
## Related projects

Part of [**php-systems-lab**](https://github.com/Researcher86/php-systems-lab),
a collection of educational PHP backend and systems programming projects. None
of them depends on another as a package - what travels between them is the
mechanism, read in one and reimplemented in the next.

* [**php-memory-lab**](https://github.com/Researcher86/php-memory-lab) — what
  the layer underneath this one costs: `mmap` and page faults, `MAP_SHARED`
  against `MAP_PRIVATE`, `msync` and what it does and does not buy, and why a
  mapped file pays only for the pages it touches where `file_get_contents()`
  pays for all of them. That is the page cache this storage engine sits on.
* [**php-concurrency**](https://github.com/Researcher86/php-concurrency) —
  processes, IPC, coordination patterns, event loops and Fibers. The
  groundwork for the locking and concurrency phases.
* [**php-mini-cache**](https://github.com/Researcher86/php-mini-cache) — the
  opposite question, in the same shape. A cache asks how to keep data fast; a
  database asks how to keep it safe.
* [**php-worker-pool**](https://github.com/Researcher86/php-worker-pool) ·
  [**php-job-queue**](https://github.com/Researcher86/php-job-queue) ·
  [**php-mini-http-server**](https://github.com/Researcher86/php-mini-http-server)
  — process runtime, background work, and the HTTP front door.
