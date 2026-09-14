<?php

declare(strict_types=1);

namespace PhpMiniDatabase\Storage;

/**
 * What a page holds. Written into the page header so that a file can be read
 * back without a separate map of which page is what, and so a reader that
 * lands on the wrong page finds out immediately instead of misparsing it.
 *
 * The numbers are part of the on-disk format and are never reused.
 */
enum PageType: int
{
    /** Allocated but carrying nothing — a page freed by VACUUM. */
    case FREE = 0;

    /** Table rows, as a slotted page. */
    case HEAP = 1;

    /** B-Tree node with child pointers (Phase 6). */
    case BTREE_INTERNAL = 2;

    /** B-Tree node with keys and record ids (Phase 6). */
    case BTREE_LEAF = 3;

    /**
     * A B-Tree index file's one header page (always page 0): holds the
     * current root page id, the only piece of the tree that changes
     * identity as it grows a level.
     */
    case BTREE_META = 4;
}
