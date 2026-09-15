<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Network\Protocol;

/** The one-byte tag `WireValue` prefixes an encoded value with. */
enum WireValueTag: int
{
    case NULL = 0;
    case BOOL = 1;
    case INT = 2;
    case FLOAT = 3;
    case STRING = 4;
    case DATETIME = 5;
}
