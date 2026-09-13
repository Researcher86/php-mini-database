<?php

declare(strict_types=1);

namespace MiniDatabase\Storage;

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
}
