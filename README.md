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

Latest finished phase: **Phase 17 — CLI Client and REPL**. `bin/minidb
connect/query/shell/import/export/user` are real: a table/json/csv/vertical
result printer, an interactive shell with readline history and keyword
autocompletion that accepts a statement spanning several lines, and a
dump/restore pair scoped to named tables (there is no `SHOW TABLES` yet
to discover a whole database on its own). `bin/minidb-user` is retired —
`user add/remove/list` now lives under `bin/minidb` itself. Server
administration (`SHOW STATUS`, PID file, daemonizing) is next.

The phase-by-phase record of the build is in [docs/PHASES.md](docs/PHASES.md),
and the reasoning behind the designs that survived is in
[docs/DECISIONS.md](docs/DECISIONS.md).

## Getting started

Requires Docker. Nothing is installed on your machine.

```bash
make install    # build the image and install dependencies
make test       # run the test suite
make analyse    # run PHPStan
make lint       # check formatting
make fix        # apply PHP CS Fixer
```

```bash
make shell      # drop into the container
```

## Layout

```
├── bin/         CLI entry points
├── benchmarks/  performance measurements
├── docs/        decisions and deep dives
├── examples/    runnable snippets
├── src/         the database itself (Schema, Storage, Index, Query, Sql, ...)
├── tests/       Unit, Integration, Stress
└── var/data/    on-disk data files (gitignored content, kept as a dir)
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
