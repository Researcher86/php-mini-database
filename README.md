# PHP Mini Database

**[🧪 PHP Systems Lab](https://github.com/Researcher86/php-systems-lab)** · Level 7 of 8 · ← [`php-mini-http-server`](https://github.com/Researcher86/php-mini-http-server) · [`php-systems-platform`](https://github.com/Researcher86/php-systems-platform) →

> A small, readable relational database written in PHP — built to explore how databases actually work from the inside.

**PHP Mini Database** is an educational database engine implemented from scratch in PHP.

The project explores the core mechanisms behind a relational database: on-disk storage, pages and records, B-tree indexes, SQL parsing and execution, transactions, locking, write-ahead logging (WAL), crash recovery, networking, authentication, and concurrent clients.

The goal is **not** to compete with MySQL, PostgreSQL, or SQLite.

The goal is to build something small enough to understand completely, but real enough to run, test, crash, recover, and benchmark.

It is a laboratory for learning how database engines work.

---

## Why This Project Exists

Most application developers use databases through an ORM or SQL client and rarely need to see what happens underneath:

```text
SQL
 ↓
Parser
 ↓
Query Planner / Executor
 ↓
Transactions
 ↓
Storage Engine
 ↓
Pages / Records / Indexes
 ↓
Files on disk
```

PHP Mini Database makes these layers explicit.

You can follow a query from SQL text all the way down to the bytes stored on disk.

The implementation is intentionally compact and readable so that individual mechanisms can be studied without navigating millions of lines of production database code.

---

## What It Covers

The database implements the major building blocks of a relational database engine.

### SQL

* SQL lexer and parser
* `CREATE TABLE`
* `DROP TABLE`
* `CREATE INDEX`
* `INSERT`
* `UPDATE`
* `DELETE`
* `SELECT`
* `WHERE`
* expressions and operators
* ordering
* limits
* joins
* aggregates
* constraints
* transactions

### Storage Engine

* on-disk database files
* fixed-size pages
* record storage
* page allocation
* table/heap storage
* catalog metadata
* persistence across process restarts
* B-tree indexes

### Query Execution

* parsed SQL statements
* execution plans
* table scans
* index scans
* filtering
* projection
* sorting
* joins
* aggregation

### Transactions

* `BEGIN`
* `COMMIT`
* `ROLLBACK`
* savepoints
* isolation levels
* row/table locking
* concurrent transactions

### Durability

* write-ahead logging (WAL)
* transaction log records
* checkpointing
* crash recovery
* rollback after an interrupted transaction
* recovery after real process termination

### Networking

The database can run in two modes:

```text
Embedded

PHP Application
      │
      ▼
Database Engine
      │
      ▼
Storage
```

or:

```text
Client
   │
   │ binary protocol
   ▼
Database Server
   │
   ▼
Database Engine
   │
   ▼
Storage
```

The client/server implementation includes a small binary wire protocol, authentication, and concurrent clients.

---

## Project Status

**All 20 milestones are complete.**

The implementation has progressed phase by phase, with each phase focused on a specific database mechanism.

The final phase covers testing, optimization, and documentation.

Current project characteristics:

* PHPStan level 8
* zero PHPStan errors
* ~90% line coverage
* unit tests
* integration tests
* crash-recovery tests
* concurrent-client tests
* client/server tests
* authentication tests
* benchmark suite
* architecture documentation
* storage documentation
* transaction and recovery documentation
* SQL reference
* protocol documentation
* runnable examples

The project is considered a **completed educational database engine** rather than an unfinished prototype.

---

## Quick Start

The project uses Docker so that nothing needs to be installed directly on the host machine.

### Install

```bash
make install
```

This builds the development image and installs the project dependencies.

### Run tests

```bash
make test
```

### Static analysis

```bash
make analyse
```

### Check formatting

```bash
make format-check
```

### Fix formatting

```bash
make format
```

### Run benchmarks

```bash
make bench
```

Benchmarks are intentionally excluded from the normal test suite because they are slower and depend on the execution environment.

### Open a shell

```bash
make shell
```

---

## Running the Database

The project provides both an embedded API and a client/server interface.

### Start the server

```bash
bin/minidb-server
```

### Connect with the CLI

```bash
bin/minidb
```

The CLI can be used to execute SQL statements against the running database.

For the complete command reference, see:

* [`docs/cli.md`](docs/cli.md)

---

## Embedded Usage

The database can also be used directly from PHP without starting a separate server.

See:

```text
examples/embedded.php
```

This mode is useful when studying the engine itself because the application and database run inside the same PHP process.

---

## Client / Server Usage

The database also supports a separate server process.

See:

```text
examples/client.php
```

The architecture becomes:

```text
PHP Client
    │
    │ Binary Protocol
    ▼
MiniDB Server
    │
    ├── SQL
    ├── Transactions
    ├── Locks
    └── Storage
```

This makes it possible to study the additional problems introduced by a database server: networking, request boundaries, authentication, concurrency, and process isolation.

---

## Transactions

Transactions are available through both SQL and the PHP API.

For example:

```sql
BEGIN;

INSERT INTO users (name)
VALUES ('Alice');

UPDATE accounts
SET balance = balance - 100
WHERE id = 1;

COMMIT;
```

Rollback is also supported:

```sql
BEGIN;

UPDATE accounts
SET balance = balance - 100
WHERE id = 1;

ROLLBACK;
```

Savepoints allow partial rollback inside a transaction:

```sql
BEGIN;

SAVEPOINT before_update;

UPDATE accounts
SET balance = balance - 100
WHERE id = 1;

ROLLBACK TO SAVEPOINT before_update;

COMMIT;
```

The transaction implementation is backed by locking and WAL-based recovery.

See:

* [`docs/transactions.md`](docs/transactions.md)

---

## Crash Recovery

One of the main goals of the project is to make durability mechanisms tangible.

The integration test suite does not only simulate errors inside one process. It also exercises recovery across **real process restarts**.

Conceptually:

```text
Transaction
     │
     ▼
   WAL
     │
     ▼
Data Pages
     │
     X
   CRASH
     │
     ▼
Restart
     │
     ▼
Recovery
     │
     ▼
Consistent Database
```

This makes it possible to experiment with the same class of problems that production database engines have to solve.

---

## Indexes

The storage layer includes B-tree indexes.

An indexed query can therefore follow a path such as:

```text
SELECT ...
WHERE id = 42
```

instead of always scanning the entire table:

```text
SQL
 ↓
Executor
 ↓
Index
 ↓
B-tree lookup
 ↓
Record
```

The implementation is intentionally small enough to inspect and understand.

See:

* [`docs/storage.md`](docs/storage.md)

---

## Architecture

The project is divided into several logical layers.

```text
┌─────────────────────────────┐
│            CLI              │
├─────────────────────────────┤
│       Client / Network      │
├─────────────────────────────┤
│       SQL / Execution       │
├─────────────────────────────┤
│       Transactions          │
│     Locks / WAL / Recovery  │
├─────────────────────────────┤
│          Storage            │
│ Pages / Records / B-Trees   │
├─────────────────────────────┤
│       Files on Disk         │
└─────────────────────────────┘
```

The source code is organized around these responsibilities rather than around a single monolithic database class.

See:

* [`docs/architecture.md`](docs/architecture.md)

---

## Repository Layout

```text
├── bin/
│   ├── minidb
│   └── minidb-server
│
├── benchmarks/
│   └── standalone benchmark scripts
│
├── config/
│
├── docs/
│   ├── sql.md
│   ├── architecture.md
│   ├── storage.md
│   ├── transactions.md
│   ├── protocol.md
│   ├── security.md
│   ├── cli.md
│   ├── PHASES.md
│   └── DECISIONS.md
│
├── examples/
│   ├── embedded.php
│   ├── client.php
│   ├── pool.php
│   └── transaction.php
│
├── src/
│   ├── Sql/
│   ├── Execution/
│   ├── Storage/
│   ├── Transaction/
│   ├── Network/
│   ├── Client/
│   └── Cli/
│
├── tests/
│   ├── Unit/
│   ├── Integration/
│   ├── Benchmark/
│   └── Support/
│
└── var/
    └── data/
```

---

## Testing

Testing is divided into several levels.

### Unit Tests

Small, isolated tests for individual database components.

```text
tests/Unit/
```

### Integration Tests

Tests involving multiple database subsystems working together.

```text
tests/Integration/
```

These include scenarios such as:

* embedded database usage
* client/server communication
* authentication
* transactions
* crash recovery
* process restarts
* concurrent clients

Some concurrency scenarios use real PHP processes via `pcntl_fork()`.

### Benchmarks

```text
tests/Benchmark/
benchmarks/
```

Benchmarks measure things such as:

* insert throughput
* select throughput
* join performance
* network round-trip latency

Run them explicitly:

```bash
make bench
```

---

## Documentation

The project is designed to be studied layer by layer.

| Document                                       | Description                                              |
| ---------------------------------------------- | -------------------------------------------------------- |
| [`docs/sql.md`](docs/sql.md)                   | SQL dialect, statements, expressions, types, constraints |
| [`docs/architecture.md`](docs/architecture.md) | How a query travels through the system                   |
| [`docs/storage.md`](docs/storage.md)           | Pages, records, heap storage, B-trees and catalog        |
| [`docs/transactions.md`](docs/transactions.md) | Transactions, isolation, locking, WAL and recovery       |
| [`docs/protocol.md`](docs/protocol.md)         | Binary client/server protocol                            |
| [`docs/security.md`](docs/security.md)         | Authentication and security boundaries                   |
| [`docs/cli.md`](docs/cli.md)                   | CLI and server commands                                  |
| [`docs/PHASES.md`](docs/PHASES.md)             | Phase-by-phase implementation history                    |
| [`docs/DECISIONS.md`](docs/DECISIONS.md)       | Important architectural decisions and their reasoning    |

---

## Design Philosophy

The project follows a few principles.

### Small enough to read

The implementation should remain understandable by a single developer.

Complexity is added only when it teaches an important database mechanism.

### Real mechanisms, not mock implementations

The project does not merely imitate database concepts.

It uses real:

* files
* pages
* indexes
* transactions
* locks
* WAL records
* processes
* sockets
* recovery procedures

### Explicit over clever

The implementation favors straightforward code over abstractions that hide the underlying mechanism.

The purpose is educational clarity.

### Measure instead of assume

Performance-sensitive areas have benchmarks.

The project is intended to make performance characteristics observable rather than relying only on theoretical reasoning.

### Learn by implementation

The best way to understand a database engine is to build one.

---

## What This Project Is Not

PHP Mini Database is **not intended for production workloads**.

Do not use it as a replacement for:

* PostgreSQL
* MySQL
* MariaDB
* SQLite
* other production database systems

It is intentionally missing many features, optimizations, operational guarantees, and battle-tested behavior expected from production databases.

Its value is educational:

> **Build a small database to understand big databases.**

---

## Learning Path

The recommended way to explore the project is to follow the layers.

```text
1. SQL
   ↓
2. Query Execution
   ↓
3. Records
   ↓
4. Pages
   ↓
5. Files
   ↓
6. Indexes
   ↓
7. Transactions
   ↓
8. Locks
   ↓
9. WAL
   ↓
10. Crash Recovery
   ↓
11. Network Protocol
   ↓
12. Concurrent Clients
```

The implementation history follows the same progression.

See [`docs/PHASES.md`](docs/PHASES.md) for the complete phase history.

---

## PHP Systems Lab

This project is part of [**PHP Systems Lab**](https://github.com/Researcher86/php-systems-lab) — a collection of small
educational PHP projects that rebuild the mechanisms behind backend
infrastructure in order to understand them. The recommended order:

| Level | Project | Focus |
| ----- | ------- | ----- |
| 1 | 🧠 [`php-memory-lab`](https://github.com/Researcher86/php-memory-lab) | memory, RSS, fork, copy-on-write, `mmap`, shared memory, FFI |
| 2 | ⚡ [`php-concurrency`](https://github.com/Researcher86/php-concurrency) | processes, IPC, concurrency patterns, event loops, Fibers (course in Russian) |
| 3 | ⚙️ [`php-worker-pool`](https://github.com/Researcher86/php-worker-pool) | persistent master/worker pool, supervision, graceful shutdown |
| 4 | 📬 [`php-job-queue`](https://github.com/Researcher86/php-job-queue) | reliable background jobs: delivery leases, ACK, retries, DLQ |
| 5 | 💾 [`php-mini-cache`](https://github.com/Researcher86/php-mini-cache) | event-driven in-memory server: RESP, pipelining, TTL, Pub/Sub |
| 6 | 🌐 [`php-mini-http-server`](https://github.com/Researcher86/php-mini-http-server) | event-driven HTTP server: parsing, routing, middleware, keep-alive |
| **7** | 🗄️ **`php-mini-database`** (this project) | **relational engine: pages, B-trees, SQL, transactions, WAL, recovery** |
| 8 | 🏗️ [`php-systems-platform`](https://github.com/Researcher86/php-systems-platform) | integration of the components into one backend platform |

These are teaching projects, not libraries: a mechanism travels between them
by being read in one and reimplemented in the next. Levels 1–7 do not depend
on each other as packages. Only [`php-systems-platform`](https://github.com/Researcher86/php-systems-platform) requires the
five components (worker pool, job queue, cache, HTTP server, database)
through Composer and runs them together as one system.

### How this project relates

**[`php-memory-lab`](https://github.com/Researcher86/php-memory-lab)** — `mmap`, page faults, `MAP_SHARED` /
`MAP_PRIVATE`, `msync` and file-backed memory: useful background for the page
cache and memory behavior underneath a storage engine.

**[`php-concurrency`](https://github.com/Researcher86/php-concurrency)** — processes, IPC, synchronization and event
loops: the background for database locking and concurrent clients.

**[`php-mini-cache`](https://github.com/Researcher86/php-mini-cache)** — the opposite side of the persistence problem.
The cache asks *how can data be kept fast?*; this project asks *how can data
be kept safe and durable?*

**[`php-systems-platform`](https://github.com/Researcher86/php-systems-platform)** — uses this database as its storage tier
(`src/Storage/Database.php`, through the client's `ConnectionPool`).

---

## License

MIT.
