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

Latest finished phase: **Phase 3 — schema and catalog**. Tables, columns,
constraints and indexes are modelled and self-validating, persisted one
`schema.json` per table under a `Catalog`; the SQL lexer and parser are
next.

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