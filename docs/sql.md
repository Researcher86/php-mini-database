# SQL Reference

This documents the SQL dialect `Sql\Parser` actually accepts and
`Execution\Executor` actually runs — a subset of standard SQL, PLAN.md §4's
own scope, not an aspirational superset. Every statement and clause below is
backed by a parser method (`src/Sql/Parser.php`) and an executor path
(`src/Execution/Executor.php`); nothing here is planned-but-unbuilt.

## Statements

| Statement | Parser method | Notes |
|-----------|----------------|-------|
| `SELECT`                | `selectStatement()`       | see below |
| `INSERT`                | `insertStatement()`       | multi-row: `VALUES (...), (...), ...` |
| `UPDATE`                | `updateStatement()`       | `SET col = expr, ...` with an optional `WHERE` |
| `DELETE`                | `deleteStatement()`       | `FROM table` with an optional `WHERE` |
| `CREATE TABLE`          | `createTableStatement()`  | see [DDL](#ddl) |
| `DROP TABLE`            | `dropTableStatement()`    | `[IF EXISTS]` |
| `ALTER TABLE`           | `alterTableStatement()`   | `ADD [COLUMN]` / `DROP [COLUMN]` only |
| `CREATE [UNIQUE] INDEX` | `createIndexStatement()`  | `ON table (col)` |
| `DROP INDEX`            | `dropIndexStatement()`    | |
| `BEGIN` / `START TRANSACTION` | `beginStatement()`  | optional `ISOLATION LEVEL ...` — see [transactions.md](transactions.md) |
| `COMMIT`                | `commitStatement()`       | |
| `ROLLBACK [TO SAVEPOINT name]` | `rollbackStatement()` | |
| `SAVEPOINT name`        | `savepointStatement()`    | |
| `RELEASE SAVEPOINT name`| `releaseSavepointStatement()` | |
| `EXPLAIN select`        | `explainStatement()`      | wraps a `SELECT` only; prints the chosen plan (see `docs/architecture.md`) |

`Sql\StatementSplitter` splits a string of several `;`-separated statements
(the CLI's `import`/`shell` need this); `Parser::parseOne()` parses exactly
one.

## `SELECT`

```
SELECT [DISTINCT] item [, item ...]
FROM table [alias] [JOIN table [alias] ON expr] ...
[WHERE expr]
[GROUP BY expr [, expr ...]]
[HAVING expr]
[ORDER BY expr [ASC|DESC] [, expr ...]]
[LIMIT n [OFFSET m]]
```

- **Select items**: a bare `*`, `table.*`, an expression, or `expr AS alias`.
  An unaliased expression is labelled by `Execution\Operator\Project::label()`
  — a bare column keeps its name, anything else gets a positional
  `column1`, `column2`, … label.
- **`FROM`**: one table, or a chain of `JOIN`s. `JOIN`/`INNER JOIN`, `LEFT
  [OUTER] JOIN`, and `RIGHT [OUTER] JOIN` are all accepted
  (`Sql\Ast\From\JoinType`). `RIGHT JOIN a b ON x` compiles by swapping
  which side is "left" and reusing `LEFT JOIN`'s own `NestedLoopJoin`
  handling (`Executor::compileJoin()`'s own comment) rather than a third
  code path. A join's `ON` must be an explicit condition; there is no
  implicit comma-join.
- **`WHERE`**/**`HAVING`**: any expression (see below); `HAVING` is only
  meaningful alongside `GROUP BY` or an aggregate select item, and is
  evaluated inside `Execution\Operator\Aggregate`, not as a separate filter
  stage — see [architecture.md](architecture.md) for why.
- **`GROUP BY`**: any expression list; every non-aggregated select item must
  be one of them (unchecked at parse time — an inconsistent query runs, it
  does not get a SQL-standard error).
- **`ORDER BY`**: a column, an expression, or a select-list alias.
  Qualified names (`t.col`) only resolve against the *pre-aggregation* row —
  once `GROUP BY` is present, `ORDER BY` runs after `Aggregate` against its
  flat, unqualified output row, so a grouped query orders by the item's
  output label (`ORDER BY total`, not `ORDER BY t.total`).
- **`LIMIT`/`OFFSET`**: always the last stage, after `DISTINCT`.
- **Subqueries are not supported.** `WHERE id IN (SELECT ...)` and any
  other nested `SELECT` throws `ExecutionException('Subqueries are not
  supported yet.')` — a named gap, not silently wrong results. Derived
  tables (`FROM (SELECT ...) x`) are likewise rejected at the planner.

### Expressions

Literals (`123`, `'text'` with `''`-doubled quotes, `TRUE`/`FALSE`, `NULL`),
column references (`col`, `table.col`), `?` parameter placeholders,
arithmetic (`+ - * /`), comparison (`= <> < <= > >=`), `AND`/`OR`/`NOT`,
`IS [NOT] NULL`, `[NOT] BETWEEN a AND b`, `[NOT] IN (list)`, `[NOT] LIKE
pattern` (`%`/`_` wildcards), unary `-`/`NOT`, and parentheses for grouping —
`Sql\Parser`'s precedence climbs multiplication over addition over
comparison over `NOT` over `AND` over `OR`, matching standard SQL.

Functions: `COUNT(*)`, `COUNT([DISTINCT] expr)`, `SUM`, `AVG`, `MIN`, `MAX`
(`Execution\Operator\Aggregate::AGGREGATE_FUNCTIONS`) — usable only where an
aggregate makes sense, i.e. a select item, `HAVING`, or `ORDER BY` on a
grouped query. `CURRENT_TIMESTAMP`, `CURRENT_DATE`, `CURRENT_TIME` are
niladic (no parentheses required). Any other bare `NAME(args)` parses as a
generic `FunctionCall`, but the evaluator only actually implements the ones
above — an unrecognised function throws at execution, not at parse time.

## DDL

```
CREATE TABLE [IF NOT EXISTS] name (
    column type [modifier ...],
    ...
)

modifier ::= NOT NULL
           | PRIMARY KEY
           | UNIQUE
           | DEFAULT expr
           | CHECK (expr)
           | REFERENCES table (column) [ON DELETE action] [ON UPDATE action]

action ::= NO ACTION | RESTRICT | CASCADE | SET NULL
```

A `REFERENCES` clause inline on a column is shorthand for a single-column
foreign key — there is no separate table-level `FOREIGN KEY (...)
REFERENCES ...` clause. `ON DELETE`/`ON UPDATE` default to `NO ACTION`
(`Schema\Constraint\ReferentialAction`); `CASCADE` and `SET_NULL` are the
two that actually rewrite child rows (see
[ConstraintTest](../tests/Integration/ConstraintTest.php) for both, and for
a cascade that is itself blocked one level further down).

`ALTER TABLE` supports exactly `ADD [COLUMN] coldef` and `DROP [COLUMN]
name` — no `RENAME`, no `ALTER COLUMN`, no constraint changes after the
fact.

`CREATE [UNIQUE] INDEX name ON table (column)` — one column per index; no
multi-column or expression indexes. `DROP INDEX name`.

## Column types

| Written as | Class | Storage |
|---|---|---|
| `INT` | `IntType` | 4-byte signed |
| `BIGINT` | `BigIntType` | 8-byte signed |
| `BOOL` | `BoolType` | 1 byte |
| `VARCHAR(n)` | `VarcharType` | length-prefixed, up to `n` bytes |
| `DECIMAL(p,s)` | `DecimalType` | `p` total digits, `s` after the point — the only type whose wire code alone cannot reconstruct it, since the scale decides where the point goes (`Schema\Type\TypeFactory`'s own docblock) |
| `DATE` | `DateType` | |
| `DATETIME` | `DateTimeType` | microsecond precision |
| `BLOB` | `BlobType` | raw bytes, length-prefixed |

`Schema\Type\TypeFactory::fromName()` parses the written form
case-insensitively and ignores internal whitespace (`"varchar ( 255 )"`
works); `VARCHAR`/`DECIMAL`'s parameters are the only part that is strict.
See [storage.md](storage.md) for each type's exact on-disk byte layout.

## Constraints

`PRIMARY KEY`, `UNIQUE`, `NOT NULL`, `CHECK (expr)`, and foreign keys are all
enforced by `Execution\ConstraintEnforcer` on every `INSERT`/`UPDATE` (and,
for foreign keys, `DELETE`) — never silently accepted and checked later. A
violation throws `Exception\ConstraintViolationException`, and a multi-row
`INSERT` that fails partway through leaves none of its rows committed (see
[ConstraintTest](../tests/Integration/ConstraintTest.php)).

## Prepared statements and parameters

Every `?` in a statement binds positionally to the matching entry of a
`list<mixed>` parameters array, both embedded (`Executor::run($sql,
$parameters)`) and over the wire (`Message\Query`/`Execute` carry
`$parameters` as a separate field, never interpolated into the SQL text —
see [security.md](security.md)'s injection-safety note).
`Connection::prepare()`/`Statement::execute()` (PLAN.md §8.2) parse once
server-side and re-execute with different parameters many times; see
[protocol.md](protocol.md#message-types) for `PREPARE`/`EXECUTE`'s wire
shape.
