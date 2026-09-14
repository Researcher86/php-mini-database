<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast;

/**
 * The root of one parsed SQL statement: SELECT, INSERT, UPDATE, DELETE, or a
 * DDL statement. `Parser::parse()` returns one of these per statement in the
 * source text.
 */
interface Statement extends Node
{
}
