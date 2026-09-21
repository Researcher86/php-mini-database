# Implementation Plan for `php-mini-database` (Relational DBMS with TCP Server)

## 0. Brief Description

`php-mini-database` is an embeddable and client-server relational database in PHP. The server listens on a TCP port, accepts client connections over its own binary protocol, executes SQL queries, and returns results. The library can be used both in embedded mode (in-process) and in client-server mode.

**Key idea:** a lightweight relational DBMS with an SQL parser, B-Tree indexes, WAL transactions, a TCP server with its own protocol, authentication, connection pooling, and a CLI client.

---

## 1. Goals and Scope

### 1.1. Goals

- Implement a relational model: tables, rows, columns, types, constraints.
- Implement an SQL-like language: DDL, DML, SELECT with JOIN, GROUP BY, aggregates.
- Implement B-Tree indexes and a query planner.
- Implement transactions via WAL with ACID.
- Implement a TCP server with multi-client support.
- Develop a custom binary protocol (wire protocol).
- Implement user authentication (SCRAM-like or challenge-response).
- Implement a client library for PHP.
- Provide a CLI server and CLI client.
- Cover the code with tests, benchmarks, and documentation.

### 1.2. Out of Scope

- Full ANSI SQL:2016 compatibility.
- PostgreSQL/MySQL protocol compatibility (not required).
- Replication, sharding, clustering.
- Stored procedures, triggers, production-grade views.
- TLS out of the box (optional in v1.1).
- Support for > 10 million rows per table.

---

## 2. Requirements

### 2.1. Functional Requirements

#### Relational Model
- [ ] Tables, columns, types, constraints.
- [ ] `PRIMARY KEY`, `FOREIGN KEY`, `UNIQUE`, `NOT NULL`, `CHECK`, `DEFAULT`.
- [ ] B-Tree indexes (regular and unique).

#### SQL
- [ ] DDL: `CREATE TABLE`, `DROP TABLE`, `ALTER TABLE`, `CREATE INDEX`, `DROP INDEX`.
- [ ] DML: `INSERT`, `UPDATE`, `DELETE`, `SELECT`.
- [ ] `JOIN` (INNER, LEFT, RIGHT).
- [ ] `GROUP BY`, `HAVING`, `ORDER BY`, `LIMIT`, `OFFSET`, `DISTINCT`.
- [ ] Subqueries in `WHERE` and `FROM`.
- [ ] Aggregates: `COUNT`, `SUM`, `AVG`, `MIN`, `MAX`.
- [ ] String/numeric/date functions.

#### Transactions
- [x] `BEGIN`, `COMMIT`, `ROLLBACK`.
- [x] `SAVEPOINT`, `ROLLBACK TO SAVEPOINT`.
- [x] Isolation levels: `READ COMMITTED`, `REPEATABLE READ`, `SERIALIZABLE`.
- [x] ACID via WAL and lock manager.

#### TCP Server
- [x] Listen on a TCP port (default 5433).
- [x] Multi-client mode (fork/process pool or event loop).
- [x] Custom binary protocol.
- [x] Authentication by login/password (challenge-response).
- [x] Prepared statements support.
- [x] Session-level transaction support.
- [ ] Idle/query timeouts.
- [x] Max connections limit.
- [x] Graceful shutdown.
- [x] Query and error logging.
- [x] `SHOW STATUS`, `SHOW CONNECTIONS`, `KILL <id>`.

#### Client
- [x] PHP client library.
- [x] Connection pool.
- [x] Reconnect on failure.
- [x] Connect/read/write timeouts.
- [x] Prepared statements support.
- [x] Transaction support.

#### CLI
- [x] `minidb-server` — start the server.
- [x] `minidb` — CLI client with REPL.
- [x] Import/export SQL dump.
- [ ] Backup/restore.

#### Other
- [x] `EXPLAIN` query plan.
- [x] Metrics: connections, queries/sec, errors.
- [ ] Configuration via file + ENV + flags.

### 2.2. Non-Functional Requirements

- PHP >= 8.1 (enum, readonly, fibers).
- PSR-4, PSR-12.
- Runtime dependencies: minimal; `ext-pcntl`, `ext-posix`, `ext-sockets` allowed.
- Optional: `ext-event` or `ext-ev` for event loop.
- Dev dependencies: PHPUnit, PHPStan, PHP-CS-Fixer, Infection.
- Atomic file writes: temp + `rename`.
- File locks via `flock`.
- Performance: up to 1,000,000 rows per table.
- Test coverage ≥ 85%.
- Cross-platform: Linux, macOS (Windows with fork limitations).
- Streaming results for large result sets.
- Protection against SQL injection at the protocol level (prepared statements).

---

## 3. Architecture

### 3.1. General Diagram

```text
+---------------------+        +---------------------+
|   PHP Client Lib    |        |    CLI minidb       |
|  Connection, Pool   |        |  (uses Client Lib)  |
+----------+----------+        +----------+----------+
           |                              |
           |        TCP (binary proto)    |
           +--------------+---------------+
                          |
                +---------v---------+
                |    TCP Server     |
                |  Acceptor, Pool   |
                +---------+---------+
                          |
                +---------v---------+
                |  Session Manager  |
                |  Auth, Tx state   |
                +---------+---------+
                          |
                +---------v---------+
                |  Query Pipeline   |
                |  Parse → Plan →   |
                |  Optimize → Exec  |
                +---------+---------+
                          |
                +---------v---------+
                |  Storage Engine   |
                |  Heap, B-Tree,    |
                |  WAL, Locks       |
                +---------+---------+
                          |
                +---------v---------+
                |    File System    |
                +-------------------+
```

### 3.2. Layers

```text
+--------------------------------------+
|            Network Layer             |
|  Server, Acceptor, Session, Proto    |
+--------------------------------------+
|            SQL Interface             |
|  Lexer, Parser, AST, Planner         |
+--------------------------------------+
|         Execution Engine             |
|  Executor, Operators, Expressions    |
+--------------------------------------+
|         Relational Model             |
|  Table, Row, Column, Schema, Types   |
+--------------------------------------+
|         Storage Engine               |
|  PageManager, HeapFile, BTreeIndex   |
+--------------------------------------+
|       Transaction Manager            |
|  WAL, LockManager, MVCC              |
+--------------------------------------+
|         Infrastructure               |
|  FileSystem, AtomicWriter, Buffer    |
+--------------------------------------+
|            File System               |
+--------------------------------------+
```

### 3.3. Main Components

#### Network
- `Network\Server` — TCP server, acceptor, event loop.
- `Network\ServerConfig` — configuration.
- `Network\Session` — client session, transaction state, prepared statements.
- `Network\SessionManager` — session registry, `KILL`, limits.
- `Network\Protocol\Frame` — protocol frame.
- `Network\Protocol\Message` — message types.
- `Network\Protocol\Codec` — serialization/deserialization.
- `Network\Auth\Authenticator` — login/password verification.
- `Network\Auth\ScramChallenge` — challenge-response.
- `Network\Auth\UserStore` — user storage.

#### Client
- `Client\Connection` — TCP connection, handshake, auth.
- `Client\ConnectionPool` — connection pool.
- `Client\Statement` — prepared statement.
- `Client\ResultSet` — result iterator.
- `Client\ClientException`.

#### SQL
- `Sql\Lexer`, `Sql\Parser`, `Sql\Ast\*`.
- `Sql\Planner`, `Sql\Optimizer`, `Sql\Rule\*`.

#### Execution
- `Execution\Executor`.
- `Execution\Operator\*` (SeqScan, IndexScan, Filter, Project, Join, Sort, Limit, Aggregate).
- `Execution\Expression\Evaluator`.

#### Schema
- `Schema\Database`, `Schema\Table`, `Schema\Column`, `Schema\Row`, `Schema\Type\*`, `Schema\Constraint\*`.

#### Storage
- `Storage\PageManager`, `Storage\HeapFile`, `Storage\BTreeIndex`, `Storage\Catalog`.

#### Transaction
- `Transaction\TransactionManager`, `Transaction\Wal`, `Transaction\LockManager`.

#### Infrastructure
- `Infrastructure\FileSystem`, `AtomicWriter`, `FileLock`, `Path`, `Config`, `Logger`.

### 3.4. Principles

- Single writer, multiple readers at the DB level.
- WAL-first: changes go to the journal first, then to data.
- Atomic write: temp + `rename`.
- Immutable AST and plans.
- Streaming results.
- Errors are not swallowed but thrown with context.
- Protocol is versioned.
- Client-server and embedded modes share the same execution engine.

---

## 4. Project Structure

```text
php-mini-database/
├── bin/
│   ├── minidb-server
│   └── minidb
├── src/
│   ├── Network/
│   │   ├── Server.php
│   │   ├── ServerConfig.php
│   │   ├── Session.php
│   │   ├── SessionManager.php
│   │   ├── Acceptor.php
│   │   ├── EventLoop.php
│   │   ├── Protocol/
│   │   │   ├── Frame.php
│   │   │   ├── Message.php
│   │   │   ├── MessageType.php
│   │   │   ├── Codec.php
│   │   │   ├── Opcode.php
│   │   │   └── ResultEncoder.php
│   │   └── Auth/
│   │       ├── Authenticator.php
│   │       ├── ScramChallenge.php
│   │       ├── UserStore.php
│   │       └── PasswordHash.php
│   ├── Client/
│   │   ├── Connection.php
│   │   ├── ConnectionPool.php
│   │   ├── Statement.php
│   │   ├── ResultSet.php
│   │   ├── ClientConfig.php
│   │   └── ClientException.php
│   ├── Sql/
│   │   ├── Lexer.php
│   │   ├── Token.php
│   │   ├── TokenType.php
│   │   ├── Parser.php
│   │   ├── Ast/
│   │   │   ├── Node.php
│   │   │   ├── SelectStatement.php
│   │   │   ├── InsertStatement.php
│   │   │   ├── UpdateStatement.php
│   │   │   ├── DeleteStatement.php
│   │   │   ├── CreateTableStatement.php
│   │   │   ├── DropTableStatement.php
│   │   │   ├── AlterTableStatement.php
│   │   │   ├── CreateIndexStatement.php
│   │   │   ├── Expression.php
│   │   │   └── ...
│   │   ├── Planner/
│   │   │   ├── LogicalPlan.php
│   │   │   ├── PhysicalPlan.php
│   │   │   └── Planner.php
│   │   └── Optimizer/
│   │       ├── Optimizer.php
│   │       └── Rule/
│   │           ├── PredicatePushdown.php
│   │           ├── ConstantFolding.php
│   │           └── IndexSelection.php
│   ├── Execution/
│   │   ├── Executor.php
│   │   ├── Operator/
│   │   │   ├── Operator.php
│   │   │   ├── SeqScan.php
│   │   │   ├── IndexScan.php
│   │   │   ├── Filter.php
│   │   │   ├── Project.php
│   │   │   ├── NestedLoopJoin.php
│   │   │   ├── HashJoin.php
│   │   │   ├── Sort.php
│   │   │   ├── Limit.php
│   │   │   └── Aggregate.php
│   │   └── Expression/
│   │       ├── Evaluator.php
│   │       ├── BinaryOp.php
│   │       ├── ColumnRef.php
│   │       ├── Literal.php
│   │       └── FunctionCall.php
│   ├── Schema/
│   │   ├── Database.php
│   │   ├── Table.php
│   │   ├── Column.php
│   │   ├── Row.php
│   │   ├── Schema.php
│   │   ├── Constraint/
│   │   │   ├── Constraint.php
│   │   │   ├── PrimaryKey.php
│   │   │   ├── ForeignKey.php
│   │   │   ├── UniqueConstraint.php
│   │   │   ├── NotNull.php
│   │   │   ├── CheckConstraint.php
│   │   │   └── DefaultValue.php
│   │   └── Type/
│   │       ├── Type.php
│   │       ├── IntType.php
│   │       ├── DecimalType.php
│   │       ├── VarcharType.php
│   │       ├── BoolType.php
│   │       ├── DateType.php
│   │       └── BlobType.php
│   ├── Storage/
│   │   ├── PageManager.php
│   │   ├── Page.php
│   │   ├── HeapFile.php
│   │   ├── BTreeIndex.php
│   │   ├── Catalog.php
│   │   └── RecordSerializer.php
│   ├── Transaction/
│   │   ├── TransactionManager.php
│   │   ├── Transaction.php
│   │   ├── Wal.php
│   │   ├── WalRecord.php
│   │   ├── LockManager.php
│   │   └── IsolationLevel.php
│   ├── Infrastructure/
│   │   ├── FileSystem.php
│   │   ├── AtomicWriter.php
│   │   ├── FileLock.php
│   │   ├── Path.php
│   │   ├── Config.php
│   │   └── Logger.php
│   ├── Exception/
│   │   ├── DatabaseException.php
│   │   ├── NetworkException.php
│   │   ├── AuthException.php
│   │   ├── ProtocolException.php
│   │   ├── ParserException.php
│   │   ├── ExecutionException.php
│   │   ├── ConstraintViolationException.php
│   │   ├── TransactionException.php
│   │   └── StorageException.php
│   ├── Backup/
│   │   ├── Dumper.php
│   │   ├── Restorer.php
│   │   └── BackupManager.php
│   └── Cli/
│       ├── ServerApplication.php
│       ├── ClientApplication.php
│       ├── Repl.php
│       └── Command/
│           ├── ServeCommand.php
│           ├── QueryCommand.php
│           ├── ShellCommand.php
│           ├── ImportCommand.php
│           ├── ExportCommand.php
│           ├── UserCommand.php
│           ├── BackupCommand.php
│           └── RestoreCommand.php
├── config/
│   ├── server.php
│   └── users.php
├── tests/
│   ├── Unit/
│   │   ├── Sql/
│   │   ├── Execution/
│   │   ├── Storage/
│   │   ├── Transaction/
│   │   ├── Network/
│   │   └── Client/
│   ├── Integration/
│   │   ├── SqlEndToEndTest.php
│   │   ├── TransactionTest.php
│   │   ├── ConstraintTest.php
│   │   ├── ServerClientTest.php
│   │   ├── AuthTest.php
│   │   └── ConcurrencyTest.php
│   ├── Benchmark/
│   │   ├── InsertBench.php
│   │   ├── SelectBench.php
│   │   ├── JoinBench.php
│   │   └── NetworkBench.php
│   └── Fixtures/
├── examples/
│   ├── embedded.php
│   ├── client.php
│   ├── pool.php
│   └── transaction.php
├── docs/
│   ├── sql.md
│   ├── architecture.md
│   ├── protocol.md
│   ├── storage.md
│   ├── transactions.md
│   ├── security.md
│   └── cli.md
├── composer.json
├── phpunit.xml
├── phpstan.neon
├── .php-cs-fixer.php
├── .gitignore
├── README.md
└── PLAN.md
```

---

## 5. TCP Protocol

### 5.1. General Principles

- Binary protocol over TCP.
- Fixed-structure frames: header + payload.
- Versioning via handshake.
- All numbers are big-endian.
- All strings are length-prefixed (uint32) in UTF-8.
- NULL values are encoded with a bitmap.

### 5.2. Frame Format

```text
+--------+--------+--------+--------+
| magic (4 bytes): 0x4D 0x44 0x42 0x31 |  "MDB1"
+--------+--------+--------+--------+
| version (uint16)                  |
+--------+--------+--------+--------+
| type    (uint8)                    |
+--------+--------+--------+--------+
| flags   (uint8)                    |
+--------+--------+--------+--------+
| length  (uint32) — payload length  |
+--------+--------+--------+--------+
| payload (length bytes)             |
+------------------------------------+
```

### 5.3. Message Types

| Code | Name | Direction | Description |
|------|------|-----------|-------------|
| 0x01 | HELLO | C → S | Start handshake, client version |
| 0x02 | HELLO_ACK | S → C | Server version, salt |
| 0x03 | AUTH | C → S | Login + challenge response |
| 0x04 | AUTH_OK | S → C | Authentication successful |
| 0x05 | AUTH_FAIL | S → C | Authentication failed |
| 0x10 | QUERY | C → S | SQL query (text) |
| 0x11 | QUERY_RESULT | S → C | Result (rows) |
| 0x12 | QUERY_ERROR | S → C | Execution error |
| 0x13 | PREPARE | C → S | Prepare query |
| 0x14 | PREPARE_OK | S → C | Prepared statement ID |
| 0x15 | EXECUTE | C → S | Execute prepared |
| 0x16 | CLOSE_STMT | C → S | Close prepared |
| 0x17 | COPY_IN | C → S | Bulk insert |
| 0x18 | COPY_OUT | S → C | Bulk export |
| 0x20 | BEGIN | C → S | Begin transaction |
| 0x21 | COMMIT | C → S | Commit |
| 0x22 | ROLLBACK | C → S | Rollback |
| 0x23 | SAVEPOINT | C → S | Set savepoint |
| 0x30 | PING | C → S | Ping |
| 0x31 | PONG | S → C | Pong |
| 0x40 | CANCEL | C → S | Cancel current query |
| 0x50 | GOODBYE | C ↔ S | Close |
| 0x60 | SHOW_STATUS | C → S | Server status |
| 0x61 | SHOW_CONNECTIONS | C → S | Connection list |
| 0x62 | KILL | C → S | Kill connection |

### 5.4. Handshake

```text
C → S: HELLO { protocol_version, client_name, client_version, capabilities }
S → C: HELLO_ACK { server_version, auth_method, salt, capabilities }
C → S: AUTH { username, challenge_response }
S → C: AUTH_OK { session_id, server_time } | AUTH_FAIL { reason }
```

### 5.5. Authentication (Challenge-Response)

- The server stores `salt` and `hash = H(password, salt)`.
- On connection, the client:
    1. Receives `salt` and a random `nonce` from the server.
    2. Computes `response = HMAC(hash, nonce)`.
    3. Sends `username` and `response`.
- The server compares `response` with the expected value.
- The password is never transmitted in plaintext.

### 5.6. SELECT Result Format

```text
payload:
  uint16 column_count
  for each column:
    string name
    uint8  type_code
    uint8  flags (nullable, primary key, etc.)
  uint64 row_count (or 0xFFFFFFFFFFFFFFFF for streaming)
  rows:
    uint8  null_bitmap[(column_count + 7) / 8]
    for each non-null column:
      value (by type)
```

### 5.7. Error Codes

| Code | Name | Description |
|------|------|-------------|
| 0x01 | PARSER_ERROR | Syntax error |
| 0x02 | TABLE_NOT_FOUND | Table not found |
| 0x03 | COLUMN_NOT_FOUND | Column not found |
| 0x04 | TYPE_MISMATCH | Incompatible types |
| 0x05 | CONSTRAINT_VIOLATION | Constraint violation |
| 0x06 | TRANSACTION_ERROR | Transaction error |
| 0x07 | DEADLOCK | Deadlock |
| 0x08 | STORAGE_ERROR | Storage error |
| 0x09 | AUTH_FAILED | Authentication failed |
| 0x0A | PERMISSION_DENIED | Permission denied |
| 0x0B | QUERY_CANCELLED | Query cancelled |
| 0x0C | TIMEOUT | Timeout |

---

## 6. Storage Format

### 6.1. Directories

```text
data/
└── mydb/
    ├── catalog.json
    ├── users.json
    ├── wal/
    │   ├── wal.0001.log
    │   └── wal.0002.log
    ├── tables/
    │   ├── users/
    │   │   ├── schema.json
    │   │   ├── heap.dat
    │   │   ├── pk.idx
    │   │   └── idx_email.idx
    │   └── orders/
    │       ├── schema.json
    │       ├── heap.dat
    │       ├── pk.idx
    │       └── idx_user_id.idx
    ├── transactions/
    │   └── tx_20250101_120000.state
    └── locks/
        ├── users.lock
        └── orders.lock
```

### 6.2. Pages

- Page size: 8192 bytes.
- Header: `page_id`, `type`, `free_space`, `slot_count`.
- Types: `HEAP`, `BTREE_INTERNAL`, `BTREE_LEAF`, `FREE`.

### 6.3. Table Schema

`schema.json`:

```json
{
  "name": "users",
  "columns": [
    {"name": "id", "type": "INT", "not_null": true},
    {"name": "email", "type": "VARCHAR(255)", "not_null": true},
    {"name": "age", "type": "INT", "default": null}
  ],
  "constraints": [
    {"type": "PRIMARY_KEY", "columns": ["id"]},
    {"type": "UNIQUE", "columns": ["email"], "name": "uq_users_email"}
  ],
  "indexes": [
    {"name": "idx_users_email", "columns": ["email"], "unique": true}
  ]
}
```

### 6.4. Users

`users.json`:

```json
{
  "alice": {
    "salt": "base64...",
    "hash": "base64...",
    "roles": ["admin"]
  },
  "bob": {
    "salt": "base64...",
    "hash": "base64...",
    "roles": ["reader"]
  }
}
```

### 6.5. WAL

```json
{"lsn":1,"tx":1,"op":"BEGIN"}
{"lsn":2,"tx":1,"op":"INSERT","table":"users","row_id":42,"after":{...}}
{"lsn":3,"tx":1,"op":"UPDATE","table":"users","row_id":42,"before":{...},"after":{...}}
{"lsn":4,"tx":1,"op":"COMMIT"}
```

---

## 7. Server Configuration

### 7.1. File `config/server.php`

```php
<?php

return [
    'host' => getenv('MINIDB_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('MINIDB_PORT') ?: 5433),
    'data_dir' => getenv('MINIDB_DATA') ?: __DIR__ . '/../data/mydb',
    'max_connections' => 100,
    'backlog' => 128,
    'idle_timeout' => 300,
    'query_timeout' => 60,
    'auth' => [
        'enabled' => true,
        'method' => 'challenge_response',
        'user_store' => __DIR__ . '/users.php',
    ],
    'logging' => [
        'level' => 'info',
        'file' => __DIR__ . '/../var/server.log',
    ],
    'performance' => [
        'page_size' => 8192,
        'buffer_pool_size' => 1024,
        'max_prepared_statements' => 100,
    ],
];
```

### 7.2. Environment Variables

- `MINIDB_HOST`, `MINIDB_PORT`, `MINIDB_DATA`.
- `MINIDB_MAX_CONNECTIONS`.
- `MINIDB_LOG_LEVEL`.

### 7.3. Priority

`CLI flags` > `ENV` > `config/server.php` > `defaults`.

---

## 8. Public API

### 8.1. Embedded Mode

```php
use PhpMiniDatabase\Schema\Database;

$db = Database::open(__DIR__ . '/data/mydb');
$db->execute("CREATE TABLE users (id INT PRIMARY KEY, email VARCHAR(255) UNIQUE)");
$result = $db->query('SELECT * FROM users');
```

### 8.2. Client Mode

```php
use PhpMiniDatabase\Client\Connection;
use PhpMiniDatabase\Client\ClientConfig;

$config = new ClientConfig(
    host: '127.0.0.1',
    port: 5433,
    user: 'alice',
    password: 'secret',
    database: 'mydb',
    connectTimeout: 5.0,
    readTimeout: 30.0,
);

$conn = Connection::connect($config);

$result = $conn->query('SELECT id, email FROM users WHERE age >= 18');
foreach ($result as $row) {
    echo $row['email'] . PHP_EOL;
}

$stmt = $conn->prepare('INSERT INTO users (id, email, age) VALUES (?, ?, ?)');
$stmt->execute([1, 'alice@example.com', 30]);

$conn->close();
```

### 8.3. Connection Pool

```php
use PhpMiniDatabase\Client\ConnectionPool;

$pool = new ConnectionPool($config, maxConnections: 10);

$conn = $pool->acquire();
try {
    $result = $conn->query('SELECT COUNT(*) AS c FROM users');
    echo $result->fetch()['c'];
} finally {
    $pool->release($conn);
}

$pool->close();
```

### 8.4. Transactions

```php
$conn->beginTransaction();
try {
    $conn->execute("INSERT INTO users (id, email) VALUES (1, 'a@x.com')");
    $conn->execute("INSERT INTO users (id, email) VALUES (2, 'b@x.com')");
    $conn->commit();
} catch (\Throwable $e) {
    $conn->rollback();
    throw $e;
}
```

### 8.5. Exceptions

```php
use PhpMiniDatabase\Exception\NetworkException;
use PhpMiniDatabase\Exception\AuthException;
use PhpMiniDatabase\Exception\ConstraintViolationException;

try {
    $conn->query('SELECT * FROM missing_table');
} catch (ExecutionException $e) {
    // ...
}
```

---

## 9. CLI

### 9.1. Server

```bash
php bin/minidb-server start --config config/server.php
php bin/minidb-server start --host 0.0.0.0 --port 5433 --data ./data/mydb
php bin/minidb-server stop --pid-file /var/run/minidb.pid
php bin/minidb-server status
php bin/minidb-server reload
```

### 9.2. Client

```bash
php bin/minidb connect --host 127.0.0.1 --port 5433 --user alice
php bin/minidb query --host 127.0.0.1 --user alice --password secret "SELECT * FROM users"
php bin/minidb shell --host 127.0.0.1 --user alice
php bin/minidb import --host 127.0.0.1 --user alice dump.sql
php bin/minidb export --host 127.0.0.1 --user alice --output dump.sql
php bin/minidb user add alice --password secret --role admin
php bin/minidb user remove bob
php bin/minidb user list
```

### 9.3. REPL

```text
minidb> SELECT id, email FROM users;
+----+-------------------+
| id | email             |
+----+-------------------+
|  1 | alice@example.com |
|  2 | bob@example.com   |
+----+-------------------+
2 rows in set (0.001 sec)
```

### 9.4. Output Flags

- `--json`, `--csv`, `--vertical`, `--quiet`.

---

## 10. SQL Support

### 10.1. DDL

```sql
CREATE TABLE users (
    id INT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    age INT DEFAULT 0 CHECK (age >= 0),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_users_age ON users (age);
ALTER TABLE users ADD COLUMN status VARCHAR(20) DEFAULT 'active';
ALTER TABLE users DROP COLUMN status;
DROP TABLE users;
```

### 10.2. DML

```sql
INSERT INTO users (id, email, age) VALUES (1, 'a@x.com', 30);
INSERT INTO users (id, email, age) VALUES (2, 'b@x.com', 25), (3, 'c@x.com', 35);
UPDATE users SET age = age + 1 WHERE email LIKE '%@x.com';
DELETE FROM users WHERE age < 18;
```

### 10.3. SELECT

```sql
SELECT id, email FROM users WHERE age >= 18 ORDER BY email ASC LIMIT 10 OFFSET 0;
SELECT COUNT(*), AVG(age) FROM users GROUP BY status HAVING COUNT(*) > 1;
SELECT u.email, o.total FROM users u INNER JOIN orders o ON o.user_id = u.id WHERE o.total > 100;
```

### 10.4. Service Commands (Extension)

```sql
SHOW STATUS;
SHOW CONNECTIONS;
SHOW TABLES;
SHOW INDEXES FROM users;
KILL 42;
EXPLAIN SELECT * FROM users WHERE age > 18;
```

---

## 11. Implementation Stages

### Milestone 0. Project Setup

**Goal:** create the skeleton.

- [x] Initialize `composer.json`.
- [x] Configure PSR-4: `PhpMiniDatabase\` → `src/`.
- [x] Install PHPUnit, PHPStan, PHP-CS-Fixer.
- [x] Create `phpunit.xml`, `phpstan.neon`, `.php-cs-fixer.php`.
- [x] Configure GitHub Actions.
- [x] Create `README.md` and `PLAN.md`.

**Result:** project builds, tests run, linters work.

**Estimate:** 1–2 days.

---

### Milestone 1. Data Types and Serialization

- [x] `Type` interface.
- [x] Implementations: `IntType`, `BigIntType`, `VarcharType`, `DecimalType`, `BoolType`, `DateType`, `DateTimeType`, `BlobType`.
- [x] Type casting and validation.
- [x] `RecordSerializer` for packing rows.
- [x] `ValueCodec` for serializing values into the protocol.
- [x] Tests for types, serialization, codec.

**Estimate:** 3–4 days.

---

### Milestone 2. Storage Engine

- [x] `Page`, `PageManager`.
- [x] `HeapFile`: insert, read, delete, scan.
- [x] `AtomicWriter`, `FileSystem`, `FileLock`.
- [x] Compaction (VACUUM).
- [x] Storage tests.

**Estimate:** 5–6 days.

---

### Milestone 3. Schema and Catalog

- [x] `Database`, `Table`, `Column`, `Schema`.
- [x] `Catalog`: reading/writing metadata.
- [x] Constraints: `PrimaryKey`, `Unique`, `NotNull`, `ForeignKey`, `Check`, `Default`.
- [x] Schema validation.
- [x] Schema and catalog tests.

**Estimate:** 4–5 days.

---

### Milestone 4. SQL Lexer and Parser

- [x] `Lexer`, `Token`, `TokenType`.
- [x] `Parser` (recursive descent).
- [x] AST nodes for all constructs.
- [x] Expressions and functions.
- [x] `ParserException` with position.
- [x] Parser tests.

**Estimate:** 7–10 days.

---

### Milestone 5. Execution Engine (Basic)

- [x] `Executor`.
- [x] Operators: `SeqScan`, `Filter`, `Project`, `Limit`.
- [x] Expression `Evaluator`.
- [x] `INSERT`, `UPDATE`, `DELETE`, `SELECT` (without JOIN).
- [x] End-to-end tests.

**Estimate:** 6–8 days.

---

### Milestone 6. B-Tree Indexes

- [x] `BTreeIndex`: insert, search, delete, range.
- [x] Disk persistence.
- [x] `IndexScan`.
- [x] Index usage by the planner.
- [x] Unique indexes.
- [x] Tests and benchmarks.

**Estimate:** 7–10 days.

---

### Milestone 7. JOIN, GROUP BY, Aggregates

- [x] `NestedLoopJoin`, `HashJoin`.
- [x] `LEFT JOIN`, `RIGHT JOIN`, `INNER JOIN`.
- [x] `Sort`, `Aggregate`.
- [x] `GROUP BY`, `HAVING`, `DISTINCT`.
- [x] JOIN and aggregate tests.

**Estimate:** 8–10 days.

---

### Milestone 8. Transactions and WAL

- [x] `Wal`, `WalRecord`.
- [x] `TransactionManager`, `Transaction`.
- [x] `BEGIN`, `COMMIT`, `ROLLBACK`.
- [x] `SAVEPOINT`.
- [x] `LockManager` (row/table locks).
- [x] Isolation levels.
- [x] Recovery after crash.
- [x] Transaction tests.

**Estimate:** 10–14 days.

---

### Milestone 9. Planner and Optimizer

- [x] `Planner`, `Optimizer`.
- [x] Rules: predicate pushdown, constant folding, index selection, join reordering.
- [x] `EXPLAIN`.
- [x] Plan tests.

**Estimate:** 7–10 days.

---

### Milestone 10. Integrity Constraints

- [x] `NOT NULL`, `UNIQUE`, `PRIMARY KEY`.
- [x] `FOREIGN KEY` with `ON DELETE`/`ON UPDATE`.
- [x] `CHECK`, `DEFAULT`.
- [x] Constraint tests.

**Estimate:** 5–7 days.

---

### Milestone 11. TCP Protocol

**Goal:** define and implement the wire protocol.

- [x] `Frame`, `Message`, `MessageType`, `Opcode`.
- [x] `Codec` — frame serialization/deserialization.
- [x] `ResultEncoder` — result encoding.
- [x] Partial read handling (streaming).
- [x] Protocol error handling.
- [x] Codec tests: roundtrip, edge cases, corrupted frames.
- [x] Documentation `docs/protocol.md`.

**Result:** protocol fully defined and tested.

**Estimate:** 5–7 days.

---

### Milestone 12. TCP Server (Core)

**Goal:** server accepts connections and executes simple queries.

- [x] `Server`, `Acceptor`, `EventLoop`.
- [x] `ServerConfig`.
- [x] `Session`, `SessionManager`.
- [x] Handshake (`HELLO`, `HELLO_ACK`).
- [x] Handle `QUERY` without authentication (dev mode).
- [x] Handle `QUERY_RESULT`, `QUERY_ERROR`.
- [x] `PING`/`PONG`.
- [x] Graceful shutdown by signal.
- [x] Logging.
- [x] Tests: connect, query, disconnect.

**Result:** server executes SQL from a client.

**Estimate:** 7–10 days.

---

### Milestone 13. Authentication

**Goal:** secure connections.

- [x] `Authenticator`, `ScramChallenge`, `UserStore`, `PasswordHash`.
- [x] Messages `AUTH`, `AUTH_OK`, `AUTH_FAIL`.
- [x] CLI `user add/remove/list`.
- [x] Password hashing (Argon2id or bcrypt).
- [x] Challenge-response via HMAC.
- [x] Authentication tests: success, failure, retry, brute-force protection.

**Result:** only authorized clients can work with the DB.

**Estimate:** 5–6 days.

---

### Milestone 14. Prepared Statements

**Goal:** parameterized queries over the network.

- [x] `PREPARE`, `PREPARE_OK`, `EXECUTE`, `CLOSE_STMT`.
- [x] Store prepared statements in the session.
- [x] Limit on the number of prepared statements.
- [x] Prepared statement tests.
- [x] Protection against SQL injection.

**Estimate:** 4–5 days.

---

### Milestone 15. Transactions over the Network

**Goal:** transaction management via the protocol.

- [x] Messages `BEGIN`, `COMMIT`, `ROLLBACK`, `SAVEPOINT`.
- [x] Transaction state in `Session`.
- [x] Handling timeouts and disconnects.
- [x] Transaction tests via the client.

**Estimate:** 3–4 days.

---

### Milestone 16. Client Library

**Goal:** convenient PHP client.

- [x] `ClientConfig`, `Connection`, `Statement`, `ResultSet`.
- [x] Handshake and auth in the client.
- [x] Connect/read/write timeouts.
- [x] Reconnect on failure.
- [x] `ConnectionPool`.
- [x] Error handling.
- [x] Client and pool tests.

**Estimate:** 6–8 days.

---

### Milestone 17. CLI Client and REPL

- [x] `bin/minidb` with commands `connect`, `query`, `shell`, `import`, `export`, `user`, `backup`, `restore`.
- [x] REPL with history and autocompletion.
- [x] Output in table/json/csv/vertical.
- [x] CLI tests.

**Estimate:** 5–6 days.

---

### Milestone 18. Server Administration

- [x] `SHOW STATUS`, `SHOW CONNECTIONS`, `KILL <id>`.
- [x] Metrics: connections, queries/sec, errors.
- [x] PID file.
- [x] Daemonize (`--daemon`).
- [x] Handle `SIGHUP` (reload), `SIGTERM` (graceful stop).
- [x] Administration tests.

**Estimate:** 4–5 days.

---

### Milestone 19. Backup, Dump, Restore

- [ ] `Dumper`: SQL dump of schema and data.
- [ ] `Restorer`.
- [ ] `BackupManager`: tar.gz backups.
- [ ] Works via server and embedded.
- [ ] Roundtrip tests.

**Estimate:** 3–4 days.

---

### Milestone 20. Testing, Optimization, Documentation

- [ ] Unit tests ≥ 85%.
- [ ] Integration tests (embedded + client-server).
- [ ] Concurrency tests (many clients).
- [ ] Load tests (network benchmark).
- [ ] Profiling and optimization.
- [ ] PHPStan level 8.
- [ ] Documentation: SQL, protocol, architecture, security, CLI.
- [ ] Examples in `examples/`.

**Estimate:** 7–10 days.

---

## 12. Testing

### 12.1. Unit Tests

- Lexer, Parser, AST.
- Types, Serializer, Codec.
- B-Tree, HeapFile, PageManager.
- Filter, Sort, Projection, Aggregates.
- Planner, Optimizer.
- WAL, TransactionManager, LockManager.
- Protocol Codec, Frame, Message.
- Client Connection, Statement, ResultSet.
- Auth: PasswordHash, ScramChallenge.

### 12.2. Integration Tests

- Embedded: full SQL cycle.
- Client-server: connect → auth → query → close.
- Prepared statements.
- Transactions via the client.
- Concurrent clients (N parallel).
- Connection drop during a query.
- Recovery after server crash.
- Integrity constraints.
- Import/export via CLI.

### 12.3. Concurrency Tests

- 10, 50, 100 simultaneous clients.
- Simultaneous writes to one table.
- Reads during writes.
- Deadlock detection.
- Timeouts and query cancellation.

### 12.4. Benchmarks

- 1k, 10k, 100k, 1M rows.
- Insert/select/update/delete.
- Search without index vs with index.
- Nested loop vs hash join.
- Network measurements: latency, throughput, connections/sec.
- Connection pool: reuse vs new connection.

---

## 13. Security

### 13.1. Authentication

- Argon2id for password storage.
- Challenge-response via HMAC-SHA256.
- Unique salt per user.
- Protection against replay attacks via nonce.

### 13.2. Authorization

- Roles: `admin`, `writer`, `reader`.
- DB/table-level privileges.
- `GRANT`, `REVOKE` (in v1.1).

### 13.3. Network

- Bind to `127.0.0.1` by default.
- Optional: TLS in v1.1.
- Frame size limit (OOM protection).
- Idle/query timeouts.
- Max connections limit.
- Rate limiting on auth attempts.

### 13.4. SQL Injection

- Prepared statements as the main path.
- Literal escaping in the parser.
- Identifier validation.

### 13.5. File System

- Validate collection/table names.
- Path traversal protection.
- Data file permissions: 0600.

---

## 14. CI/CD

### 14.1. GitHub Actions

- PHP matrix: 8.1, 8.2, 8.3, 8.4.
- Steps:
    - `composer install`
    - `php-cs-fixer --dry-run`
    - `phpstan analyse`
    - `phpunit`
    - `composer validate`
- Separate job: start server + client tests.

---

## 15. Risks and Solutions

| Risk | Solution |
|------|----------|
| File corruption during write | AtomicWriter: temp + rename |
| Concurrent access | flock + WAL + lock manager |
| Slow search | B-Tree, predicate pushdown |
| Memory leak on large data | Iterators, streaming, limits |
| Path traversal | Name validation, normalization |
| SQL parser complexity | Incremental support, test coverage |
| Deadlock in transactions | Lock manager with cycle detection |
| Data loss on crash | WAL + recovery + backups |
| Slow JOINs | Hash join, optimizer |
| Heap file growth | VACUUM |
| B-Tree complexity | Incremental implementation, tests |
| Client disconnect during transaction | Timeout + auto-rollback |
| Slowloris on TCP | Timeouts on handshake and idle |
| Auth brute force | Rate limiting + backoff |
| OOM on large results | Streaming + frame size limit |
| Fork issues on Windows | Document limitations |

---

## 16. Definition of Done

- [ ] All functional requirements are met.
- [ ] Test coverage ≥ 85%.
- [ ] PHPStan level 8 with no errors.
- [ ] PHP-CS-Fixer with no violations.
- [ ] Documentation: SQL, protocol, architecture, security, CLI.
- [ ] Usage examples (embedded + client-server).
- [ ] CI passes.
- [ ] Benchmarks are published.
- [ ] Server sustains 100 simultaneous clients.
- [ ] No critical bugs.

---

## 17. Example: Embedded

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use PhpMiniDatabase\Schema\Database;

$db = Database::open(__DIR__ . '/data/mydb');

$db->execute('
    CREATE TABLE IF NOT EXISTS users (
        id INT PRIMARY KEY,
        email VARCHAR(255) NOT NULL UNIQUE,
        age INT DEFAULT 0 CHECK (age >= 0)
    )
');

$db->execute("INSERT INTO users (id, email, age) VALUES (1, 'alice@example.com', 30)");

foreach ($db->query('SELECT * FROM users') as $row) {
    print_r($row);
}
```

---

## 18. Example: Client-Server

### 19.1. Start the Server

```bash
php bin/minidb-server start --host 127.0.0.1 --port 5433 --data ./data/mydb
```

### 19.2. Connect the Client

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use PhpMiniDatabase\Client\Connection;
use PhpMiniDatabase\Client\ClientConfig;

$config = new ClientConfig(
    host: '127.0.0.1',
    port: 5433,
    user: 'alice',
    password: 'secret',
    database: 'mydb',
);

$conn = Connection::connect($config);

$stmt = $conn->prepare('INSERT INTO users (id, email, age) VALUES (?, ?, ?)');
$stmt->execute([1, 'alice@example.com', 30]);

$result = $conn->query('SELECT id, email FROM users WHERE age >= ?', [18]);
foreach ($result as $row) {
    printf("#%d %s\n", $row['id'], $row['email']);
}

$conn->close();
```

---

## 19. Appendix: Protocol Error Format

```json
{
  "error": {
    "code": "CONSTRAINT_VIOLATION",
    "message": "Duplicate entry 'alice@example.com' for key 'uq_users_email'",
    "context": {
      "table": "users",
      "constraint": "uq_users_email",
      "value": "alice@example.com"
    }
  }
}
```

Error codes:

- `PARSER_ERROR`, `TABLE_NOT_FOUND`, `COLUMN_NOT_FOUND`.
- `TYPE_MISMATCH`, `CONSTRAINT_VIOLATION`.
- `TRANSACTION_ERROR`, `DEADLOCK`.
- `STORAGE_ERROR`, `AUTH_FAILED`, `PERMISSION_DENIED`.
- `QUERY_CANCELLED`, `TIMEOUT`.

---

## 20. Appendix: Transaction Isolation

| Level | Dirty Read | Non-Repeatable Read | Phantom Reads |
|-------|------------|---------------------|---------------|
| READ COMMITTED | no | yes | yes |
| REPEATABLE READ | no | no | yes |
| SERIALIZABLE | no | no | no |

Implementation:

- `READ COMMITTED` — read locks released immediately.
- `REPEATABLE READ` — row snapshot.
- `SERIALIZABLE` — predicate locks or SSI.

---

## 21. Appendix: Connection Lifecycle

```text
Client                          Server
  |                               |
  |------- TCP connect ---------->|
  |------- HELLO ---------------->|
  |<------ HELLO_ACK -------------|
  |------- AUTH ----------------->|
  |<------ AUTH_OK / AUTH_FAIL ---|
  |                               |
  |------- QUERY ----------------->|
  |<------ QUERY_RESULT ----------|
  |                               |
  |------- PREPARE -------------->|
  |<------ PREPARE_OK ------------|
  |------- EXECUTE -------------->|
  |<------ QUERY_RESULT ----------|
  |                               |
  |------- BEGIN ---------------->|
  |------- QUERY ---------------->|
  |------- COMMIT --------------->|
  |                               |
  |------- GOODBYE -------------->|
  |<------ GOODBYE ---------------|
  |------- TCP close ------------>|
```

---

## 22. Appendix: Implementation Order (Checklist)

- [x] Milestone 0. Project Setup.
- [x] Milestone 1. Data Types and Serialization.
- [x] Milestone 2. Storage Engine.
- [x] Milestone 3. Schema and Catalog.
- [x] Milestone 4. SQL Lexer and Parser.
- [x] Milestone 5. Execution Engine (Basic).
- [x] Milestone 6. B-Tree Indexes.
- [x] Milestone 7. JOIN, GROUP BY, Aggregates.
- [x] Milestone 8. Transactions and WAL.
- [x] Milestone 9. Planner and Optimizer.
- [x] Milestone 10. Integrity Constraints.
- [x] Milestone 11. TCP Protocol.
- [x] Milestone 12. TCP Server (Core).
- [x] Milestone 13. Authentication.
- [x] Milestone 14. Prepared Statements.
- [x] Milestone 15. Transactions over the Network.
- [x] Milestone 16. Client Library.
- [x] Milestone 17. CLI Client and REPL.
- [x] Milestone 18. Server Administration.
- [ ] Milestone 19. Backup, Dump, Restore.
- [ ] Milestone 20. Testing, Optimization, Documentation.

---

**Summary:** The plan covers architecture, TCP protocol, authentication, client library, implementation stages, testing, security, CI/CD, risks, and definition of done for a PHP relational mini-DBMS with client-server mode. It is a learning project, not a package meant to ship — the plan is a roadmap from an empty repository to a feature-complete, well-tested implementation, not a release train.