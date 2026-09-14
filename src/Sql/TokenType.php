<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql;

/**
 * Every kind of token the lexer produces.
 *
 * Keywords get their own case each rather than being folded into a single
 * `KEYWORD` case carrying the word as data, because the parser mostly wants
 * to ask "is the next token FROM?" — a `match` on a case is what that reads
 * as. The word itself is still on the `Token` (as `$text`), for the handful
 * of places (error messages, `IDENTIFIER`) that need it.
 *
 * Keywords are not reserved from identifiers at the lexer level — `Lexer`
 * emits `TABLE` for the bare word `table` wherever it appears, and it is the
 * `Parser` that decides whether a keyword-shaped word is allowed to stand in
 * for an identifier at a given point in the grammar (it currently is not:
 * see `Parser::identifier()`). A schema whose column happens to be named
 * `key` is a real thing this may need to grow room for, but it is a parser
 * concession to make later, not a lexer one.
 */
enum TokenType
{
    // Literals and names
    case IDENTIFIER;
    case NUMBER;
    case STRING;

    // DQL
    case SELECT;
    case FROM;
    case WHERE;
    case AS;
    case DISTINCT;
    case GROUP;
    case HAVING;
    case ORDER;
    case BY;
    case ASC;
    case DESC;
    case LIMIT;
    case OFFSET;

    // Joins
    case JOIN;
    case INNER;
    case LEFT;
    case RIGHT;
    case OUTER;
    case ON;

    // DML
    case INSERT;
    case INTO;
    case VALUES;
    case UPDATE;
    case SET;
    case DELETE;

    // DDL
    case CREATE;
    case TABLE;
    case DROP;
    case ALTER;
    case ADD;
    case COLUMN;
    case INDEX;
    case IF;
    case EXISTS;

    // Constraints
    case PRIMARY;
    case KEY;
    case FOREIGN;
    case REFERENCES;
    case UNIQUE;
    case NOT;
    case NULL;
    case DEFAULT;
    case CHECK;
    case CONSTRAINT;
    case CASCADE;
    case RESTRICT;
    case NO;
    case ACTION;

    // Boolean and predicate keywords
    case TRUE;
    case FALSE;
    case AND;
    case OR;
    case IN;
    case LIKE;
    case BETWEEN;
    case IS;

    // Transactions
    case BEGIN;
    case START;
    case TRANSACTION;
    case COMMIT;
    case ROLLBACK;
    case WORK;
    case SAVEPOINT;
    case RELEASE;
    case TO;
    case ISOLATION;
    case LEVEL;
    case READ;
    case COMMITTED;
    case REPEATABLE;
    case SERIALIZABLE;

    // Diagnostics
    case EXPLAIN;

    // Punctuation and operators
    case LPAREN;
    case RPAREN;
    case COMMA;
    case SEMICOLON;
    case DOT;
    case STAR;
    case PLACEHOLDER;
    case EQ;
    case NEQ;
    case LT;
    case LTE;
    case GT;
    case GTE;
    case PLUS;
    case MINUS;
    case SLASH;
    case PERCENT;

    case EOF;

    /**
     * The keyword each spelling maps to, matched case-insensitively by the
     * lexer. The single source of truth for "which words are keywords" —
     * adding one here is the only change needed for the lexer to recognise
     * it.
     *
     * @return array<string, self>
     */
    public static function keywords(): array
    {
        static $keywords = null;

        return $keywords ??= [
            'SELECT' => self::SELECT,
            'FROM' => self::FROM,
            'WHERE' => self::WHERE,
            'AS' => self::AS,
            'DISTINCT' => self::DISTINCT,
            'GROUP' => self::GROUP,
            'HAVING' => self::HAVING,
            'ORDER' => self::ORDER,
            'BY' => self::BY,
            'ASC' => self::ASC,
            'DESC' => self::DESC,
            'LIMIT' => self::LIMIT,
            'OFFSET' => self::OFFSET,
            'JOIN' => self::JOIN,
            'INNER' => self::INNER,
            'LEFT' => self::LEFT,
            'RIGHT' => self::RIGHT,
            'OUTER' => self::OUTER,
            'ON' => self::ON,
            'INSERT' => self::INSERT,
            'INTO' => self::INTO,
            'VALUES' => self::VALUES,
            'UPDATE' => self::UPDATE,
            'SET' => self::SET,
            'DELETE' => self::DELETE,
            'CREATE' => self::CREATE,
            'TABLE' => self::TABLE,
            'DROP' => self::DROP,
            'ALTER' => self::ALTER,
            'ADD' => self::ADD,
            'COLUMN' => self::COLUMN,
            'INDEX' => self::INDEX,
            'IF' => self::IF,
            'EXISTS' => self::EXISTS,
            'PRIMARY' => self::PRIMARY,
            'KEY' => self::KEY,
            'FOREIGN' => self::FOREIGN,
            'REFERENCES' => self::REFERENCES,
            'UNIQUE' => self::UNIQUE,
            'NOT' => self::NOT,
            'NULL' => self::NULL,
            'DEFAULT' => self::DEFAULT,
            'CHECK' => self::CHECK,
            'CONSTRAINT' => self::CONSTRAINT,
            'CASCADE' => self::CASCADE,
            'RESTRICT' => self::RESTRICT,
            'NO' => self::NO,
            'ACTION' => self::ACTION,
            'TRUE' => self::TRUE,
            'FALSE' => self::FALSE,
            'AND' => self::AND,
            'OR' => self::OR,
            'IN' => self::IN,
            'LIKE' => self::LIKE,
            'BETWEEN' => self::BETWEEN,
            'IS' => self::IS,
            'BEGIN' => self::BEGIN,
            'START' => self::START,
            'TRANSACTION' => self::TRANSACTION,
            'COMMIT' => self::COMMIT,
            'ROLLBACK' => self::ROLLBACK,
            'WORK' => self::WORK,
            'SAVEPOINT' => self::SAVEPOINT,
            'RELEASE' => self::RELEASE,
            'TO' => self::TO,
            'ISOLATION' => self::ISOLATION,
            'LEVEL' => self::LEVEL,
            'READ' => self::READ,
            'COMMITTED' => self::COMMITTED,
            'REPEATABLE' => self::REPEATABLE,
            'SERIALIZABLE' => self::SERIALIZABLE,
            'EXPLAIN' => self::EXPLAIN,
        ];
    }
}
