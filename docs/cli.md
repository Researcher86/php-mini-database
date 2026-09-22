# CLI Reference

This documents both command-line entrypoints as they actually behave —
`bin/minidb-server` (`Cli\ServerApplication`) and `bin/minidb`
(`Cli\ClientApplication`) — matching `docs/protocol.md`'s own scope and
tone: real class names, real flags, no aspirational commands PLAN.md
describes but the code does not implement.

## Argument syntax

Both entrypoints parse `$argv` the same way, through `Cli\ArgvParser`:

```text
<script> <command> [positional args...] [--option value]... [--flag]...
```

- The first bare token is the **command**; every bare token after it is a
  **positional argument**.
- `--name value` is a value-taking option. It is repeatable — `--table
  users --table posts` collects `['users', 'posts']` — and every option
  is value-taking *unless* it is one of the fixed set of boolean flags
  each application declares up front (`--daemon` for the server; `--json`/
  `--csv`/`--vertical`/`--quiet`/`--force` for the client). A boolean flag
  takes no following value.
- There is no `--name=value` form — only `--name value` (space-separated).
  `ArgvParser` has to be told which names are boolean up front for
  exactly this reason: without that list, `--json` immediately followed by
  a positional SQL string would swallow the SQL as `--json`'s "value".

## `bin/minidb-server`

```text
minidb-server <start|stop|status|reload|dump|load> [options]
```

### `start`

Starts the server in the foreground (or detached, with `--daemon`) and
runs `Network\Server::run()`'s event loop until stopped.

| Flag                 | Env var                    | Default             |
|-----------------------|-----------------------------|-----------------------|
| `--host`              | `MINIDB_HOST`               | `127.0.0.1`           |
| `--port`              | `MINIDB_PORT`                | `5433`                |
| `--data`              | `MINIDB_DATA`                | `<cwd>/data/mydb`     |
| `--max-connections`   | `MINIDB_MAX_CONNECTIONS`    | `100`                 |
| `--auth-enabled`      | `MINIDB_AUTH_ENABLED`       | `false`               |
| `--log-level`         | `MINIDB_LOG_LEVEL`          | `info`                |
| `--log-file`          | `MINIDB_LOG_FILE`           | `php://stderr`        |
| `--pid-file`          | *(none)*                    | *(none — no pid tracking)* |
| `--daemon`            | *(none)*                    | *(boolean flag; off)* |

Precedence is CLI flag, then the matching `MINIDB_*` environment
variable, then the default — PLAN.md §7.3's order, minus a
`config/server.php` file layer no milestone ever builds (`Cli\Command\ServeCommand::resolve()`
is the two-tier `$options[...][0] ?? (getenv(...) ?: $default)` that
implements this).

`--auth-enabled` and any other flag read as a boolean from a string
accepts `1`/`true`/`yes` (case-insensitively) as true; anything else is
false. `--log-level` accepts `Infrastructure\LogLevel`'s four values —
`debug`, `info`, `warning`, `error` — falling back to `info` for anything
else, including a typo (a strict validation error here would only pick
one arbitrary default over another; the practical effect is identical).

`--daemon` forks (`pcntl_fork()`), the parent exits immediately, and the
child detaches from the controlling terminal (`posix_setsid()`) and
closes `STDIN`/`STDOUT`/`STDERR`. It refuses to start at all if `--log-file`/
`MINIDB_LOG_FILE` still points at a terminal stream (`php://stderr`,
`php://stdout`, or the literal strings `STDOUT`/`STDERR`) — once detached,
nothing could ever read what got logged there again. See
[DECISIONS.md](DECISIONS.md#daemon-refuses-to-run-without-a-real---log-file).

```sh
minidb-server start --data /var/lib/minidb --port 5433 \
  --log-file /var/log/minidb.log --pid-file /run/minidb.pid --daemon
```

`--pid-file` is optional for `start` itself (a foregrounded server with
no pid file just has no `stop`/`status`/`reload` counterpart to control
it), but required for those three commands, since they act purely on the
pid it names.

### `stop` / `status` / `reload`

```text
minidb-server stop    --pid-file <path>
minidb-server status  --pid-file <path>
minidb-server reload  --pid-file <path>
```

None of the three ever opens a `Client\Connection` to the running server
— there is nothing a network round trip would buy over the plain OS
operations `Cli\PidFile` already provides: `status` and the liveness
check `stop`/`reload` both do first are `posix_kill($pid, 0)` (sends no
signal, only asks whether the process exists); `stop` sends `SIGTERM`
and polls (up to 5 seconds) until the process is gone, removing the pid
file once it is; `reload` sends `SIGHUP` and returns immediately, without waiting for or
verifying a reaction. `Network\Server::run()` installs a `SIGHUP` handler
that re-reads only `MINIDB_MAX_CONNECTIONS` from the environment and
applies it to the live `SessionManager` — the one `ServerConfig` setting
that can genuinely change without restarting anything (`$host`/`$port`
are the already-bound listening socket; `$dataDirectory` is the one
`Schema\Database` already open). There is no `config/server.php` file to
reload from, and nothing else here reloads live. See
[DECISIONS.md](DECISIONS.md#bin-minidb-servers-pid-file-lifecycle-never-talks-to-the-server).

Exit code `1` (with `Not running.` on stderr) if the pid file is missing,
empty, or names a dead process. `stop`/`reload` on an already-stopped
server report the same thing rather than silently succeeding.

### `dump` / `load`

```text
minidb-server dump --data <dir> [--output <file>] [--table <name>]...
minidb-server load --data <dir> --input <file>
```

Milestone 19's SQL-level backup, reading `Schema\Database` directly —
**embedded**, not over the wire, which is exactly what lets `dump`
discover every table on its own and emit `CREATE TABLE` alongside
`INSERT` (`bin/minidb export`, the network-mode equivalent below, can do
neither). `--table` (repeatable) restricts the dump to named tables;
omitted, every table in the catalog is dumped, in foreign-key dependency
order, best effort
([DECISIONS.md](DECISIONS.md#dumper-orders-tables-by-foreign-key-dependency-best-effort)).
`--output` defaults to stdout. `load` runs the dumped SQL back through
`Backup\Restorer` against a (possibly different) data directory —
statement by statement, stopping at the first failure, the same as
`bin/minidb import` below.

See
[DECISIONS.md](DECISIONS.md#dumperrestorerbackupmanager-are-embedded-only)
for why this pair, `bin/minidb backup`/`restore`, and `bin/minidb
export`/`import` are four different things rather than one: `dump`/`load`
are SQL text, embedded; `backup`/`restore` are a physical tar.gz of the
whole data directory, also embedded; `export`/`import` are SQL text (data
only, no schema), over the network.

## `bin/minidb`

```text
minidb <connect|query|shell|import|export|user|status|connections|kill|backup|restore> [args] [options]
```

Every subcommand accepts `--host` (default `127.0.0.1`), `--port`
(default `5433`), `--user`, `--password` to build the
`Client\ClientConfig` it connects with, except `user`/`backup`/`restore`,
which never open a network connection at all (see their own sections
below).

`--json`, `--csv`, `--vertical` select `Cli\ResultPrinter`'s output
format for any subcommand that prints a result set (`query`, `shell`,
`status`, `connections`); the default is a plain ASCII table. Giving more
than one is an error. `--quiet` suppresses the trailing `N row(s) in set
(X sec)` / `Query OK, N row(s) affected (X sec)` status line every
non-quiet run prints after its result.

### `connect`

```text
minidb connect --host 127.0.0.1 --port 5433 [--user alice --password secret]
```

Opens a connection, prints `Connected to <host>:<port>[ as <user>].`
(unless `--quiet`), closes it, exits `0` — or exits `1` with the
connection error on stderr. Mostly useful as a liveness/credentials
check before scripting something larger.

### `query`

```text
minidb query "SELECT * FROM users WHERE id = 1"
```

One statement, one connection, then exit — the SQL is the first
positional argument (quote it for the shell). Prints the result in the
selected format, then the status line, then exits `0`; a failed
statement prints `ERROR: <message>` to stderr and exits `1`.

### `shell`

```text
minidb shell
```

Connects, then hands off to `Cli\Repl` — an interactive prompt
(`minidb> `, continuing with `     -> ` across an unterminated
statement) that reads SQL, runs it, prints it, and repeats until `exit`,
`quit`, or EOF (Ctrl-D). A line is only run once it tokenizes cleanly
*and* its last real token is `;` — an unclosed string or a `CREATE TABLE`
still missing its closing `)` both read as "keep reading," not an error
([DECISIONS.md](DECISIONS.md#repls-statement-completeness-check-is-tokenize-and-look-at-the-last-token)).
One buffered line may hold several `;`-terminated statements
(`SELECT 1; SELECT 2;`); each runs in turn.

`SHOW STATUS`, `SHOW CONNECTIONS`, and `KILL <id>` are recognized as
plain text (case-insensitively, `Cli\AdminCommand::parse()`) and
dispatched to `Client\Connection::showStatus()`/`showConnections()`/`kill()`
directly rather than sent as `Query` SQL — this project's SQL grammar was
never actually extended to parse `SHOW`/`KILL`
([DECISIONS.md](DECISIONS.md#show_statusshow_connectionskill-are-typed-messages-only)).
Anything else goes to the server as ordinary SQL.

Command history and Tab-completion come from `ext-readline` when it is
loaded (keyword completion only — table and column names aren't
discoverable over the wire, so they can't be offered); without it, input
falls back to plain line reads with neither feature.

A failed statement whose error carries an `ErrorCode` (bad SQL, a
constraint violation) is reported and the shell keeps going; a failure
with no error code (the socket died) is reported and the shell exits —
there is nothing left to run further statements against.

### `import` / `export`

```text
minidb import dump.sql
minidb export --table users --table orders [--output dump.sql]
```

`import` reads a file, splits it into individual statements
(`Sql\StatementSplitter` — the wire protocol's `Query` only ever carries
one statement at a time), and runs them through one connection in order,
printing `N. OK (M rows affected)` per statement, stopping at the first
failure (the same default `mysql < dump.sql` has without `--force` — the
`--force` flag does not exist here). `export` writes `INSERT` statements
only, for exactly the tables named with `--table` (repeatable, and
required — there is no `SHOW TABLES` for it to discover tables on its
own); `--output` defaults to stdout. Unlike `minidb-server dump`, this
never writes `CREATE TABLE` — there is no wire message to ask the server
for a table's schema, only its rows
([DECISIONS.md](DECISIONS.md#importexport-are-scoped-to-what-the-protocol-supports)).

### `user`

```text
minidb user add <username> --password <password> [--role <role>]... --data <dir>
minidb user remove <username> --data <dir>
minidb user list --data <dir>
```

Edits `<data>/users.json` directly through `Network\Auth\UserStore` —
**no network connection at all**, the same local-filesystem model
`backup`/`restore` use, and for the same reason: this has to work whether
or not a server is currently running against that data directory, and
there is no wire message for "create a user" in the first place. `--role`
is repeatable and optional. This used to be the standalone
`bin/minidb-user` script; it is folded into `bin/minidb user` as of
Milestone 19
([DECISIONS.md](DECISIONS.md#user-management-stays-local-now-under-binminidb-itself)
records the original reasoning for keeping it local, which is unchanged
by the later merge).

### `status` / `connections` / `kill`

```text
minidb status
minidb connections
minidb kill <connection-id>
```

One-shot, scriptable equivalents of `shell`'s `SHOW STATUS`/`SHOW
CONNECTIONS`/`KILL <id>`, as real subcommands (Milestone 18) rather than
SQL-shaped text — consistent with `user`/`import`/`export` already being
subcommands. `status` prints one row: `uptime_seconds`,
`active_connections`, `total_connections`, `total_queries`,
`total_errors`, `queries_per_second`. `connections` prints one row per
open session: `id`, `username`, `connected_at`, `authenticated`. `kill`
takes the numeric connection `id` `connections` just showed and prints
`Connection <id> killed.` on success, or `ERROR: ...` (e.g. an unknown
id) on failure.

### `backup` / `restore`

```text
minidb backup  --data <dir> --output <archive.tar.gz>
minidb restore --archive <archive.tar.gz> --data <dir> [--force]
```

A physical, whole-data-directory copy via `Backup\BackupManager` — `ext-phar`'s
`PharData`, not a shelled-out `tar` binary
([DECISIONS.md](DECISIONS.md#backupmanager-uses-phardata-not-a-shelled-out-tar-binary)).
Local filesystem operations, like `user`: no connection is opened, since
a caller runs these on a machine that can already see the server's data
directory. `restore` refuses to write into a `--data` directory that
already has files in it unless `--force` is also given
([DECISIONS.md](DECISIONS.md#backupmanagerrestore-refuses-a-non-empty-target-directory)) —
a caller has to mean it before two databases' files end up mixed
together.

## Output formats

`Cli\OutputFormat` / `Cli\ResultPrinter`, shared by `query`, `shell`,
`status`, and `connections`:

| Flag          | Shape |
|----------------|-------|
| *(none)*       | An ASCII table: a header row, a separator, one row per result row, column widths sized to the widest cell (`mb_strlen`-aware). |
| `--json`       | A pretty-printed JSON array of objects, one per row (`JSON_PRETTY_PRINT \| JSON_UNESCAPED_SLASHES \| JSON_UNESCAPED_UNICODE`). |
| `--csv`        | RFC 4180-style CSV via `fputcsv()`, header row included. |
| `--vertical`   | One `*** N. row ***` block per row, `column: value` pairs beneath it, column names right-aligned to the widest — the shape a very wide result reads better in than a table. |

Every format renders a cell the same way: `NULL` for `null`, `1`/`0` for
a boolean, `Y-m-d H:i:s` for a `DateTimeImmutable`, otherwise `(string)
$value`. A statement with nothing to return (DDL, `BEGIN`/`COMMIT`/…)
prints nothing in any format — zero columns is `Network\Protocol\ResultEncoder`'s
own signal for that, and the status line's `Query OK` already says so.

## Exit codes

`0` on success, `1` on any failure this CLI itself detects (connection
error, malformed input, a missing required flag, a statement error) —
there is no wider taxonomy of exit codes; a script that needs to know
*why* a run failed reads stderr.
