<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql;

use PhpMiniDatabase\Exception\ParserException;
use PhpMiniDatabase\Schema\Constraint\ReferentialAction;
use PhpMiniDatabase\Sql\Ast\AlterAction\AddColumn;
use PhpMiniDatabase\Sql\Ast\AlterAction\DropColumn;
use PhpMiniDatabase\Sql\Ast\AlterTableStatement;
use PhpMiniDatabase\Sql\Ast\Assignment;
use PhpMiniDatabase\Sql\Ast\BeginStatement;
use PhpMiniDatabase\Sql\Ast\ColumnDefinition;
use PhpMiniDatabase\Sql\Ast\CommitStatement;
use PhpMiniDatabase\Sql\Ast\CreateIndexStatement;
use PhpMiniDatabase\Sql\Ast\CreateTableStatement;
use PhpMiniDatabase\Sql\Ast\DeleteStatement;
use PhpMiniDatabase\Sql\Ast\DropIndexStatement;
use PhpMiniDatabase\Sql\Ast\DropTableStatement;
use PhpMiniDatabase\Sql\Ast\ExplainStatement;
use PhpMiniDatabase\Sql\Ast\Expression;
use PhpMiniDatabase\Sql\Ast\Expression\Between;
use PhpMiniDatabase\Sql\Ast\Expression\BinaryOp;
use PhpMiniDatabase\Sql\Ast\Expression\BinaryOperator;
use PhpMiniDatabase\Sql\Ast\Expression\ColumnRef;
use PhpMiniDatabase\Sql\Ast\Expression\FunctionCall;
use PhpMiniDatabase\Sql\Ast\Expression\InList;
use PhpMiniDatabase\Sql\Ast\Expression\InSubquery;
use PhpMiniDatabase\Sql\Ast\Expression\IsNull;
use PhpMiniDatabase\Sql\Ast\Expression\Like;
use PhpMiniDatabase\Sql\Ast\Expression\Literal;
use PhpMiniDatabase\Sql\Ast\Expression\Placeholder;
use PhpMiniDatabase\Sql\Ast\Expression\ScalarSubquery;
use PhpMiniDatabase\Sql\Ast\Expression\Star;
use PhpMiniDatabase\Sql\Ast\Expression\UnaryOp;
use PhpMiniDatabase\Sql\Ast\Expression\UnaryOperator;
use PhpMiniDatabase\Sql\Ast\From\DerivedTable;
use PhpMiniDatabase\Sql\Ast\From\FromItem;
use PhpMiniDatabase\Sql\Ast\From\Join;
use PhpMiniDatabase\Sql\Ast\From\JoinType;
use PhpMiniDatabase\Sql\Ast\From\TableReference;
use PhpMiniDatabase\Sql\Ast\InsertStatement;
use PhpMiniDatabase\Sql\Ast\OrderByItem;
use PhpMiniDatabase\Sql\Ast\OrderDirection;
use PhpMiniDatabase\Sql\Ast\ReleaseSavepointStatement;
use PhpMiniDatabase\Sql\Ast\RollbackStatement;
use PhpMiniDatabase\Sql\Ast\RollbackToSavepointStatement;
use PhpMiniDatabase\Sql\Ast\SavepointStatement;
use PhpMiniDatabase\Sql\Ast\SelectItem;
use PhpMiniDatabase\Sql\Ast\SelectStatement;
use PhpMiniDatabase\Sql\Ast\Statement;
use PhpMiniDatabase\Sql\Ast\TableConstraint\CheckDefinition;
use PhpMiniDatabase\Sql\Ast\TableConstraint\ForeignKeyDefinition;
use PhpMiniDatabase\Sql\Ast\TableConstraint\PrimaryKeyDefinition;
use PhpMiniDatabase\Sql\Ast\TableConstraint\TableConstraintDefinition;
use PhpMiniDatabase\Sql\Ast\TableConstraint\UniqueDefinition;
use PhpMiniDatabase\Sql\Ast\UpdateStatement;
use PhpMiniDatabase\Transaction\IsolationLevel;

/**
 * A recursive-descent parser over `Lexer`'s tokens, producing the AST in
 * `Sql\Ast\`. One instance parses one call to `parse()`; a new SQL text
 * gets a new `Parser`.
 *
 * Expression precedence, loosest to tightest — `OR`, `AND`, prefix `NOT`,
 * comparison/`LIKE`/`IN`/`BETWEEN`/`IS NULL` (these do not chain: `a = b = c`
 * is not a thing), additive, multiplicative, unary minus, primary — is the
 * standard SQL table and is encoded as one parsing method per level, each
 * calling the next tighter one for its operands. `NOT` appears twice on
 * purpose: as a whole-expression prefix (`NOT active`) at its own low-
 * precedence level, and attached to a comparison operator (`x NOT IN (...)`,
 * `x NOT LIKE ...`, `x NOT BETWEEN ... AND ...`) inside the comparison
 * level itself, where the grammar actually has it.
 *
 * Aliases (`table u`, `expr AS total`) are accepted with or without `AS`
 * uniformly, via `optionalAlias()`. That is unambiguous here without any
 * lookahead trick: a keyword that could start the next clause (`FROM`,
 * `WHERE`, `JOIN`, ...) is never lexed as `IDENTIFIER`, so "the next token
 * is a bare identifier" already means "it can only be an alias."
 *
 * A keyword can never stand in for an identifier (`identifier()` only
 * accepts an `IDENTIFIER` token) — a table or column literally named `key`
 * needs the `"quoted"` form the lexer already supports. Loosening that is a
 * parser-only change to make later; nothing above this file would need to.
 */
final class Parser
{
    /** Zero-argument date/time functions, legal written with no parentheses. */
    private const NILADIC_FUNCTIONS = ['CURRENT_TIMESTAMP', 'CURRENT_DATE', 'CURRENT_TIME'];

    /** @var list<Token> */
    private readonly array $tokens;

    private int $position = 0;

    private int $nextPlaceholderIndex = 0;

    private function __construct(
        private readonly string $source,
    ) {
        $this->tokens = Lexer::tokenize($source);
    }

    /** @return list<Statement> */
    public static function parse(string $sql): array
    {
        $parser = new self($sql);
        $statements = [];

        while (!$parser->check(TokenType::EOF)) {
            $statements[] = $parser->statement();

            while ($parser->match(TokenType::SEMICOLON)) {
                // consume any number of trailing/separating semicolons
            }
        }

        return $statements;
    }

    /** Parse exactly one statement; anything after it is an error. */
    public static function parseOne(string $sql): Statement
    {
        $statements = self::parse($sql);

        if (count($statements) !== 1) {
            throw new ParserException(sprintf('Expected exactly one statement, found %d.', count($statements)), 1, 1);
        }

        return $statements[0];
    }

    /**
     * Parses a bare expression, not a whole statement — what
     * `Execution\ConstraintEnforcer` needs to turn a `CHECK` constraint's
     * stored text back into a tree it can evaluate, the same round trip
     * `Sql\ExpressionPrinter` promises in the other direction.
     */
    public static function parseExpression(string $sql): Expression
    {
        $parser = new self($sql);
        $expression = $parser->expression();

        if (!$parser->check(TokenType::EOF)) {
            throw $parser->error('Expected end of expression.');
        }

        return $expression;
    }

    private function statement(): Statement
    {
        return match ($this->current()->type) {
            TokenType::SELECT => $this->selectStatement(),
            TokenType::INSERT => $this->insertStatement(),
            TokenType::UPDATE => $this->updateStatement(),
            TokenType::DELETE => $this->deleteStatement(),
            TokenType::CREATE => $this->createStatement(),
            TokenType::DROP => $this->dropStatement(),
            TokenType::ALTER => $this->alterTableStatement(),
            TokenType::BEGIN, TokenType::START => $this->beginStatement(),
            TokenType::COMMIT => $this->commitStatement(),
            TokenType::ROLLBACK => $this->rollbackStatement(),
            TokenType::SAVEPOINT => $this->savepointStatement(),
            TokenType::RELEASE => $this->releaseSavepointStatement(),
            TokenType::EXPLAIN => $this->explainStatement(),
            default => throw $this->error('Expected a statement.'),
        };
    }

    private function explainStatement(): ExplainStatement
    {
        $this->expect(TokenType::EXPLAIN);
        $inner = $this->statement();

        if (!$inner instanceof SelectStatement) {
            throw $this->error('EXPLAIN only supports SELECT.');
        }

        return new ExplainStatement($inner);
    }

    private function createStatement(): Statement
    {
        return match (true) {
            $this->checkAhead(1, TokenType::TABLE) => $this->createTableStatement(),
            $this->checkAhead(1, TokenType::INDEX), $this->checkAhead(1, TokenType::UNIQUE) => $this->createIndexStatement(),
            default => throw $this->error('Expected TABLE or INDEX after CREATE.'),
        };
    }

    private function dropStatement(): Statement
    {
        return match (true) {
            $this->checkAhead(1, TokenType::TABLE) => $this->dropTableStatement(),
            $this->checkAhead(1, TokenType::INDEX) => $this->dropIndexStatement(),
            default => throw $this->error('Expected TABLE or INDEX after DROP.'),
        };
    }

    // -----------------------------------------------------------------
    // SELECT
    // -----------------------------------------------------------------

    private function selectStatement(): SelectStatement
    {
        $this->expect(TokenType::SELECT);
        $distinct = $this->match(TokenType::DISTINCT);
        $columns = $this->selectItemList();

        $from = null;
        if ($this->match(TokenType::FROM)) {
            $from = $this->fromClause();
        }

        $where = $this->match(TokenType::WHERE) ? $this->expression() : null;

        $groupBy = [];
        if ($this->match(TokenType::GROUP)) {
            $this->expect(TokenType::BY);
            $groupBy = $this->expressionList();
        }

        $having = $this->match(TokenType::HAVING) ? $this->expression() : null;

        $orderBy = [];
        if ($this->match(TokenType::ORDER)) {
            $this->expect(TokenType::BY);
            $orderBy = $this->orderByList();
        }

        $limit = $this->match(TokenType::LIMIT) ? $this->integerLiteral() : null;
        $offset = $this->match(TokenType::OFFSET) ? $this->integerLiteral() : null;

        return new SelectStatement($columns, $from, $where, $distinct, $groupBy, $having, $orderBy, $limit, $offset);
    }

    /** @return list<SelectItem> */
    private function selectItemList(): array
    {
        $items = [$this->selectItem()];

        while ($this->match(TokenType::COMMA)) {
            $items[] = $this->selectItem();
        }

        return $items;
    }

    private function selectItem(): SelectItem
    {
        if ($this->check(TokenType::STAR)) {
            $this->advance();

            return new SelectItem(new Star());
        }

        if ($this->check(TokenType::IDENTIFIER) && $this->checkAhead(1, TokenType::DOT) && $this->checkAhead(2, TokenType::STAR)) {
            $qualifier = $this->advance()->text;
            $this->advance(); // DOT
            $this->advance(); // STAR

            return new SelectItem(new Star($qualifier));
        }

        $expression = $this->expression();

        return new SelectItem($expression, $this->optionalAlias());
    }

    private function fromClause(): FromItem
    {
        $left = $this->fromItem();

        while (true) {
            $type = match (true) {
                $this->match(TokenType::JOIN) => JoinType::INNER,
                $this->matchSequence(TokenType::INNER, TokenType::JOIN) => JoinType::INNER,
                $this->matchJoinSide(TokenType::LEFT) => JoinType::LEFT,
                $this->matchJoinSide(TokenType::RIGHT) => JoinType::RIGHT,
                default => null,
            };

            if ($type === null) {
                return $left;
            }

            $right = $this->fromItem();
            $this->expect(TokenType::ON);
            $on = $this->expression();

            $left = new Join($left, $type, $right, $on);
        }
    }

    /** `LEFT [OUTER] JOIN` / `RIGHT [OUTER] JOIN`, consumed as one unit. */
    private function matchJoinSide(TokenType $side): bool
    {
        if (!$this->check($side)) {
            return false;
        }

        $mark = $this->position;
        $this->advance();
        $this->match(TokenType::OUTER);

        if ($this->match(TokenType::JOIN)) {
            return true;
        }

        $this->position = $mark;

        return false;
    }

    private function fromItem(): FromItem
    {
        if ($this->match(TokenType::LPAREN)) {
            if ($this->check(TokenType::SELECT)) {
                $query = $this->selectStatement();
                $this->expect(TokenType::RPAREN);
                $alias = $this->optionalAlias()
                    ?? throw $this->error('A derived table must be given an alias.');

                return new DerivedTable($query, $alias);
            }

            $item = $this->fromClause();
            $this->expect(TokenType::RPAREN);

            return $item;
        }

        $table = $this->identifier();

        return new TableReference($table, $this->optionalAlias());
    }

    /** @return list<OrderByItem> */
    private function orderByList(): array
    {
        $items = [$this->orderByItem()];

        while ($this->match(TokenType::COMMA)) {
            $items[] = $this->orderByItem();
        }

        return $items;
    }

    private function orderByItem(): OrderByItem
    {
        $expression = $this->expression();
        $direction = match (true) {
            $this->match(TokenType::ASC) => OrderDirection::ASC,
            $this->match(TokenType::DESC) => OrderDirection::DESC,
            default => OrderDirection::ASC,
        };

        return new OrderByItem($expression, $direction);
    }

    // -----------------------------------------------------------------
    // INSERT / UPDATE / DELETE
    // -----------------------------------------------------------------

    private function insertStatement(): InsertStatement
    {
        $this->expect(TokenType::INSERT);
        $this->expect(TokenType::INTO);
        $table = $this->identifier();

        $columns = null;
        if ($this->match(TokenType::LPAREN)) {
            $columns = $this->identifierList();
            $this->expect(TokenType::RPAREN);
        }

        $this->expect(TokenType::VALUES);

        $rows = [$this->valuesRow()];
        while ($this->match(TokenType::COMMA)) {
            $rows[] = $this->valuesRow();
        }

        return new InsertStatement($table, $columns, $rows);
    }

    /** @return list<Expression> */
    private function valuesRow(): array
    {
        $this->expect(TokenType::LPAREN);
        $values = $this->expressionList();
        $this->expect(TokenType::RPAREN);

        return $values;
    }

    private function updateStatement(): UpdateStatement
    {
        $this->expect(TokenType::UPDATE);
        $table = $this->identifier();
        $this->expect(TokenType::SET);

        $assignments = [$this->assignment()];
        while ($this->match(TokenType::COMMA)) {
            $assignments[] = $this->assignment();
        }

        $where = $this->match(TokenType::WHERE) ? $this->expression() : null;

        return new UpdateStatement($table, $assignments, $where);
    }

    private function assignment(): Assignment
    {
        $column = $this->identifier();
        $this->expect(TokenType::EQ);

        return new Assignment($column, $this->expression());
    }

    private function deleteStatement(): DeleteStatement
    {
        $this->expect(TokenType::DELETE);
        $this->expect(TokenType::FROM);
        $table = $this->identifier();

        $where = $this->match(TokenType::WHERE) ? $this->expression() : null;

        return new DeleteStatement($table, $where);
    }

    // -----------------------------------------------------------------
    // DDL
    // -----------------------------------------------------------------

    private function createTableStatement(): CreateTableStatement
    {
        $this->expect(TokenType::CREATE);
        $this->expect(TokenType::TABLE);

        $ifNotExists = false;
        if ($this->match(TokenType::IF)) {
            $this->expect(TokenType::NOT);
            $this->expect(TokenType::EXISTS);
            $ifNotExists = true;
        }

        $table = $this->identifier();
        $this->expect(TokenType::LPAREN);

        $columns = [];
        $constraints = [];

        do {
            if ($this->startsTableConstraint()) {
                $constraints[] = $this->tableConstraintDefinition();
            } else {
                $columns[] = $this->columnDefinition();
            }
        } while ($this->match(TokenType::COMMA));

        $this->expect(TokenType::RPAREN);

        return new CreateTableStatement($table, $columns, $constraints, $ifNotExists);
    }

    private function startsTableConstraint(): bool
    {
        return $this->check(TokenType::CONSTRAINT)
            || $this->check(TokenType::PRIMARY)
            || $this->check(TokenType::UNIQUE)
            || $this->check(TokenType::FOREIGN)
            || $this->check(TokenType::CHECK);
    }

    private function columnDefinition(): ColumnDefinition
    {
        $name = $this->identifier();
        $type = $this->typeName();

        $notNull = false;
        $primaryKey = false;
        $unique = false;
        $default = null;
        $check = null;
        $foreignKey = null;

        while (true) {
            if ($this->match(TokenType::NOT)) {
                $this->expect(TokenType::NULL);
                $notNull = true;
            } elseif ($this->match(TokenType::NULL)) {
                // an explicit NULL modifier changes nothing; nullable is
                // already the default
            } elseif ($this->match(TokenType::PRIMARY)) {
                $this->expect(TokenType::KEY);
                $primaryKey = true;
            } elseif ($this->match(TokenType::UNIQUE)) {
                $unique = true;
            } elseif ($this->match(TokenType::DEFAULT)) {
                // Deliberately additiveExpression(), not the full
                // expression() grammar: unparenthesized, DEFAULT's value
                // otherwise runs straight into whatever modifier keyword
                // follows it (a bare NOT would be read as the start of
                // "NOT IN/LIKE/BETWEEN" and fail on the NOT NULL that
                // follows). A literal, an arithmetic expression, or a
                // parenthesized expression of any complexity all still
                // parse; a bare unparenthesized comparison/AND/OR does not,
                // which is also all a DEFAULT ever needs to be.
                $default = $this->additiveExpression();
            } elseif ($this->match(TokenType::CHECK)) {
                $this->expect(TokenType::LPAREN);
                $check = $this->expression();
                $this->expect(TokenType::RPAREN);
            } elseif ($this->match(TokenType::REFERENCES)) {
                $referencedTable = $this->identifier();
                $this->expect(TokenType::LPAREN);
                $referencedColumn = $this->identifier();
                $this->expect(TokenType::RPAREN);
                [$onDelete, $onUpdate] = $this->referentialActions();
                $foreignKey = new ForeignKeyDefinition([$name], $referencedTable, [$referencedColumn], $onDelete, $onUpdate);
            } else {
                break;
            }
        }

        return new ColumnDefinition($name, $type, $notNull, $primaryKey, $unique, $default, $check, $foreignKey);
    }

    /** A type name as written: "INT", "VARCHAR(255)", "DECIMAL(10,2)". */
    private function typeName(): string
    {
        $name = $this->identifier();

        if (!$this->match(TokenType::LPAREN)) {
            return $name;
        }

        $parameters = [$this->expect(TokenType::NUMBER)->text];
        while ($this->match(TokenType::COMMA)) {
            $parameters[] = $this->expect(TokenType::NUMBER)->text;
        }
        $this->expect(TokenType::RPAREN);

        return sprintf('%s(%s)', $name, implode(',', $parameters));
    }

    private function tableConstraintDefinition(): TableConstraintDefinition
    {
        $name = $this->match(TokenType::CONSTRAINT) ? $this->identifier() : null;

        if ($this->match(TokenType::PRIMARY)) {
            $this->expect(TokenType::KEY);
            $this->expect(TokenType::LPAREN);
            $columns = $this->identifierList();
            $this->expect(TokenType::RPAREN);

            return new PrimaryKeyDefinition($columns);
        }

        if ($this->match(TokenType::UNIQUE)) {
            $this->expect(TokenType::LPAREN);
            $columns = $this->identifierList();
            $this->expect(TokenType::RPAREN);

            return new UniqueDefinition($columns, $name);
        }

        if ($this->match(TokenType::FOREIGN)) {
            $this->expect(TokenType::KEY);
            $this->expect(TokenType::LPAREN);
            $columns = $this->identifierList();
            $this->expect(TokenType::RPAREN);
            $this->expect(TokenType::REFERENCES);
            $referencedTable = $this->identifier();
            $this->expect(TokenType::LPAREN);
            $referencedColumns = $this->identifierList();
            $this->expect(TokenType::RPAREN);
            [$onDelete, $onUpdate] = $this->referentialActions();

            return new ForeignKeyDefinition($columns, $referencedTable, $referencedColumns, $onDelete, $onUpdate, $name);
        }

        if ($this->match(TokenType::CHECK)) {
            $this->expect(TokenType::LPAREN);
            $expression = $this->expression();
            $this->expect(TokenType::RPAREN);

            return new CheckDefinition($expression, $name);
        }

        throw $this->error('Expected PRIMARY KEY, UNIQUE, FOREIGN KEY or CHECK.');
    }

    /** @return array{0: ReferentialAction, 1: ReferentialAction} */
    private function referentialActions(): array
    {
        $onDelete = ReferentialAction::NO_ACTION;
        $onUpdate = ReferentialAction::NO_ACTION;

        while ($this->match(TokenType::ON)) {
            if ($this->match(TokenType::DELETE)) {
                $onDelete = $this->referentialAction();
            } elseif ($this->match(TokenType::UPDATE)) {
                $onUpdate = $this->referentialAction();
            } else {
                throw $this->error('Expected DELETE or UPDATE after ON.');
            }
        }

        return [$onDelete, $onUpdate];
    }

    private function referentialAction(): ReferentialAction
    {
        if ($this->match(TokenType::CASCADE)) {
            return ReferentialAction::CASCADE;
        }

        if ($this->match(TokenType::RESTRICT)) {
            return ReferentialAction::RESTRICT;
        }

        if ($this->match(TokenType::SET)) {
            $this->expect(TokenType::NULL);

            return ReferentialAction::SET_NULL;
        }

        if ($this->match(TokenType::NO)) {
            $this->expect(TokenType::ACTION);

            return ReferentialAction::NO_ACTION;
        }

        throw $this->error('Expected CASCADE, RESTRICT, SET NULL or NO ACTION.');
    }

    private function dropTableStatement(): DropTableStatement
    {
        $this->expect(TokenType::DROP);
        $this->expect(TokenType::TABLE);

        $ifExists = false;
        if ($this->match(TokenType::IF)) {
            $this->expect(TokenType::EXISTS);
            $ifExists = true;
        }

        return new DropTableStatement($this->identifier(), $ifExists);
    }

    private function alterTableStatement(): AlterTableStatement
    {
        $this->expect(TokenType::ALTER);
        $this->expect(TokenType::TABLE);
        $table = $this->identifier();

        if ($this->match(TokenType::ADD)) {
            $this->match(TokenType::COLUMN);

            return new AlterTableStatement($table, new AddColumn($this->columnDefinition()));
        }

        if ($this->match(TokenType::DROP)) {
            $this->match(TokenType::COLUMN);

            return new AlterTableStatement($table, new DropColumn($this->identifier()));
        }

        throw $this->error('Expected ADD or DROP after ALTER TABLE ' . $table . '.');
    }

    private function createIndexStatement(): CreateIndexStatement
    {
        $this->expect(TokenType::CREATE);
        $unique = $this->match(TokenType::UNIQUE);
        $this->expect(TokenType::INDEX);
        $name = $this->identifier();
        $this->expect(TokenType::ON);
        $table = $this->identifier();
        $this->expect(TokenType::LPAREN);
        $columns = $this->identifierList();
        $this->expect(TokenType::RPAREN);

        return new CreateIndexStatement($name, $table, $columns, $unique);
    }

    private function dropIndexStatement(): DropIndexStatement
    {
        $this->expect(TokenType::DROP);
        $this->expect(TokenType::INDEX);
        $name = $this->identifier();
        $this->expect(TokenType::ON);

        return new DropIndexStatement($name, $this->identifier());
    }

    // -----------------------------------------------------------------
    // Transactions
    // -----------------------------------------------------------------

    private function beginStatement(): BeginStatement
    {
        if (!$this->match(TokenType::BEGIN)) {
            $this->expect(TokenType::START);
            $this->expect(TokenType::TRANSACTION);
        }

        $this->match(TokenType::TRANSACTION);

        $level = null;
        if ($this->match(TokenType::ISOLATION)) {
            $this->expect(TokenType::LEVEL);
            $level = $this->isolationLevel();
        }

        return new BeginStatement($level);
    }

    private function isolationLevel(): IsolationLevel
    {
        if ($this->match(TokenType::READ)) {
            $this->expect(TokenType::COMMITTED);

            return IsolationLevel::READ_COMMITTED;
        }

        if ($this->match(TokenType::REPEATABLE)) {
            $this->expect(TokenType::READ);

            return IsolationLevel::REPEATABLE_READ;
        }

        if ($this->match(TokenType::SERIALIZABLE)) {
            return IsolationLevel::SERIALIZABLE;
        }

        throw $this->error('Expected READ COMMITTED, REPEATABLE READ or SERIALIZABLE.');
    }

    private function commitStatement(): CommitStatement
    {
        $this->expect(TokenType::COMMIT);
        $this->endOfTransactionKeyword();

        return new CommitStatement();
    }

    private function rollbackStatement(): Statement
    {
        $this->expect(TokenType::ROLLBACK);

        if ($this->match(TokenType::TO)) {
            $this->match(TokenType::SAVEPOINT);

            return new RollbackToSavepointStatement($this->identifier());
        }

        $this->endOfTransactionKeyword();

        return new RollbackStatement();
    }

    /** The optional, meaningless `WORK`/`TRANSACTION` some dialects allow after COMMIT/ROLLBACK. */
    private function endOfTransactionKeyword(): void
    {
        if (!$this->match(TokenType::WORK)) {
            $this->match(TokenType::TRANSACTION);
        }
    }

    private function savepointStatement(): SavepointStatement
    {
        $this->expect(TokenType::SAVEPOINT);

        return new SavepointStatement($this->identifier());
    }

    private function releaseSavepointStatement(): ReleaseSavepointStatement
    {
        $this->expect(TokenType::RELEASE);
        $this->match(TokenType::SAVEPOINT);

        return new ReleaseSavepointStatement($this->identifier());
    }

    // -----------------------------------------------------------------
    // Expressions, loosest to tightest
    // -----------------------------------------------------------------

    private function expression(): Expression
    {
        return $this->orExpression();
    }

    private function orExpression(): Expression
    {
        $left = $this->andExpression();

        while ($this->match(TokenType::OR)) {
            $left = new BinaryOp($left, BinaryOperator::OR, $this->andExpression());
        }

        return $left;
    }

    private function andExpression(): Expression
    {
        $left = $this->notExpression();

        while ($this->match(TokenType::AND)) {
            $left = new BinaryOp($left, BinaryOperator::AND, $this->notExpression());
        }

        return $left;
    }

    private function notExpression(): Expression
    {
        if ($this->match(TokenType::NOT)) {
            return new UnaryOp(UnaryOperator::NOT, $this->notExpression());
        }

        return $this->comparisonExpression();
    }

    private function comparisonExpression(): Expression
    {
        $left = $this->additiveExpression();

        $operator = match (true) {
            $this->match(TokenType::EQ) => BinaryOperator::EQUAL,
            $this->match(TokenType::NEQ) => BinaryOperator::NOT_EQUAL,
            $this->match(TokenType::LT) => BinaryOperator::LESS_THAN,
            $this->match(TokenType::LTE) => BinaryOperator::LESS_THAN_OR_EQUAL,
            $this->match(TokenType::GT) => BinaryOperator::GREATER_THAN,
            $this->match(TokenType::GTE) => BinaryOperator::GREATER_THAN_OR_EQUAL,
            default => null,
        };

        if ($operator !== null) {
            return new BinaryOp($left, $operator, $this->additiveExpression());
        }

        $negated = $this->match(TokenType::NOT);

        if ($this->match(TokenType::IN)) {
            return $this->inExpression($left, $negated);
        }

        if ($this->match(TokenType::LIKE)) {
            return new Like($left, $this->additiveExpression(), $negated);
        }

        if ($this->match(TokenType::BETWEEN)) {
            $low = $this->additiveExpression();
            $this->expect(TokenType::AND);
            $high = $this->additiveExpression();

            return new Between($left, $low, $high, $negated);
        }

        if ($negated) {
            throw $this->error('Expected IN, LIKE or BETWEEN after NOT.');
        }

        if ($this->match(TokenType::IS)) {
            $negated = $this->match(TokenType::NOT);
            $this->expect(TokenType::NULL);

            return new IsNull($left, $negated);
        }

        return $left;
    }

    private function inExpression(Expression $subject, bool $negated): Expression
    {
        $this->expect(TokenType::LPAREN);

        if ($this->check(TokenType::SELECT)) {
            $query = $this->selectStatement();
            $this->expect(TokenType::RPAREN);

            return new InSubquery($subject, $query, $negated);
        }

        $values = $this->expressionList();
        $this->expect(TokenType::RPAREN);

        return new InList($subject, $values, $negated);
    }

    private function additiveExpression(): Expression
    {
        $left = $this->multiplicativeExpression();

        while (true) {
            $operator = match (true) {
                $this->match(TokenType::PLUS) => BinaryOperator::ADD,
                $this->match(TokenType::MINUS) => BinaryOperator::SUBTRACT,
                default => null,
            };

            if ($operator === null) {
                return $left;
            }

            $left = new BinaryOp($left, $operator, $this->multiplicativeExpression());
        }
    }

    private function multiplicativeExpression(): Expression
    {
        $left = $this->unaryExpression();

        while (true) {
            $operator = match (true) {
                $this->match(TokenType::STAR) => BinaryOperator::MULTIPLY,
                $this->match(TokenType::SLASH) => BinaryOperator::DIVIDE,
                $this->match(TokenType::PERCENT) => BinaryOperator::MODULO,
                default => null,
            };

            if ($operator === null) {
                return $left;
            }

            $left = new BinaryOp($left, $operator, $this->unaryExpression());
        }
    }

    private function unaryExpression(): Expression
    {
        if ($this->match(TokenType::MINUS)) {
            return new UnaryOp(UnaryOperator::NEGATE, $this->unaryExpression());
        }

        return $this->primaryExpression();
    }

    private function primaryExpression(): Expression
    {
        $token = $this->current();

        return match ($token->type) {
            TokenType::NUMBER => $this->numberLiteral(),
            TokenType::STRING => new Literal($this->advance()->text),
            TokenType::TRUE => $this->consumeLiteral(true),
            TokenType::FALSE => $this->consumeLiteral(false),
            TokenType::NULL => $this->consumeLiteral(null),
            TokenType::PLACEHOLDER => $this->placeholder(),
            TokenType::LPAREN => $this->parenthesized(),
            TokenType::IDENTIFIER => $this->identifierExpression(),
            default => throw $this->error(sprintf('Expected an expression, found "%s".', $token->text)),
        };
    }

    private function numberLiteral(): Literal
    {
        $text = $this->advance()->text;

        return new Literal(str_contains($text, '.') ? (float) $text : (int) $text);
    }

    private function consumeLiteral(int|float|string|bool|null $value): Literal
    {
        $this->advance();

        return new Literal($value);
    }

    private function placeholder(): Placeholder
    {
        $this->advance();

        return new Placeholder($this->nextPlaceholderIndex++);
    }

    private function parenthesized(): Expression
    {
        $this->advance();

        if ($this->check(TokenType::SELECT)) {
            $query = $this->selectStatement();
            $this->expect(TokenType::RPAREN);

            return new ScalarSubquery($query);
        }

        $expression = $this->expression();
        $this->expect(TokenType::RPAREN);

        return $expression;
    }

    private function identifierExpression(): Expression
    {
        $name = $this->advance()->text;

        if ($this->check(TokenType::LPAREN)) {
            return $this->functionCall($name);
        }

        if ($this->match(TokenType::DOT)) {
            if ($this->match(TokenType::STAR)) {
                return new Star($name);
            }

            return new ColumnRef($this->identifier(), $name);
        }

        if (in_array(strtoupper($name), self::NILADIC_FUNCTIONS, true)) {
            return new FunctionCall($name);
        }

        return new ColumnRef($name);
    }

    private function functionCall(string $name): FunctionCall
    {
        $this->expect(TokenType::LPAREN);

        if ($this->match(TokenType::RPAREN)) {
            return new FunctionCall($name);
        }

        $distinct = $this->match(TokenType::DISTINCT);

        if ($this->check(TokenType::STAR) && $this->checkAhead(1, TokenType::RPAREN)) {
            $this->advance();
            $this->advance();

            return new FunctionCall($name, [new Star()], $distinct);
        }

        $arguments = $this->expressionList();
        $this->expect(TokenType::RPAREN);

        return new FunctionCall($name, $arguments, $distinct);
    }

    // -----------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------

    /** @return list<Expression> */
    private function expressionList(): array
    {
        $items = [$this->expression()];

        while ($this->match(TokenType::COMMA)) {
            $items[] = $this->expression();
        }

        return $items;
    }

    /** @return list<string> */
    private function identifierList(): array
    {
        $items = [$this->identifier()];

        while ($this->match(TokenType::COMMA)) {
            $items[] = $this->identifier();
        }

        return $items;
    }

    private function identifier(): string
    {
        return $this->expect(TokenType::IDENTIFIER)->text;
    }

    private function integerLiteral(): int
    {
        return (int) $this->expect(TokenType::NUMBER)->text;
    }

    private function optionalAlias(): ?string
    {
        if ($this->match(TokenType::AS)) {
            return $this->identifier();
        }

        if ($this->check(TokenType::IDENTIFIER)) {
            return $this->advance()->text;
        }

        return null;
    }

    private function current(): Token
    {
        return $this->tokens[$this->position];
    }

    private function advance(): Token
    {
        $token = $this->tokens[$this->position];

        if (!$token->is(TokenType::EOF)) {
            $this->position++;
        }

        return $token;
    }

    private function check(TokenType $type): bool
    {
        return $this->current()->is($type);
    }

    private function checkAhead(int $ahead, TokenType $type): bool
    {
        $index = $this->position + $ahead;
        $token = $this->tokens[$index] ?? $this->tokens[count($this->tokens) - 1];

        return $token->is($type);
    }

    private function match(TokenType $type): bool
    {
        if (!$this->check($type)) {
            return false;
        }

        $this->advance();

        return true;
    }

    private function matchSequence(TokenType ...$types): bool
    {
        foreach ($types as $ahead => $type) {
            // $types is TokenType ...$types - $ahead is always the plain
            // int index PHP always gives a variadic array; PHPStan just
            // does not track that specifically through a generic foreach.
            if (!$this->checkAhead((int) $ahead, $type)) {
                return false;
            }
        }

        for ($i = 0; $i < count($types); $i++) {
            $this->advance();
        }

        return true;
    }

    private function expect(TokenType $type): Token
    {
        if (!$this->check($type)) {
            throw $this->error(sprintf('Expected %s, found "%s".', $type->name, $this->current()->text));
        }

        return $this->advance();
    }

    private function error(string $message): ParserException
    {
        return ParserException::at($this->source, $this->current()->position, $message);
    }
}
