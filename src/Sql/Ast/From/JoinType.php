<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Sql\Ast\From;

enum JoinType
{
    case INNER;
    case LEFT;
    case RIGHT;
}
