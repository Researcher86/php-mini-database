<?php

declare(strict_types=1);

/**
 * Formatting is a settled question in this repository, not a per-file
 * judgement call: PER-CS 2.0 (PSR-12's successor) plus a handful of rules
 * that match how the code was already written by hand.
 *
 * The point of having a formatter here at all is that this project exists
 * to be read. A diff should show a change in behaviour, never a change in
 * brace placement.
 *
 * Two conventions no rule can express, so they are written down here:
 *
 *  - A constructor's parameters go one per line with a trailing comma, even
 *    when they would fit on one. Adding or removing a dependency is then a
 *    one-line diff, and a promoted property can carry its own comment.
 *  - Dependencies are promoted, with their default in the signature
 *    (`private readonly Clock $clock = new SystemClock()`), rather than
 *    taken as nullable and resolved in the body. The signature is then the
 *    whole truth about what the object holds, and `readonly` goes on
 *    everything that is never reassigned.
 */

// PhpCsFixer\Finder hardcodes a `*.php` name filter, and this project's
// bin/ scripts are deliberately extensionless (run via their shebang line,
// never `require`d) - `->in(['bin'])` alone would silently check none of
// them. `append()` bypasses that filter for exactly the files named here.
$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/examples',
    ])
    ->append([
        __DIR__ . '/bin/minidb-server',
        __DIR__ . '/bin/minidb',
    ]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        // Every file here already declares it; the fixer is what keeps a
        // new one from forgetting.
        'declare_strict_types' => true,
        // Global classes are imported (`use RuntimeException;`) rather than
        // written as `\RuntimeException` inline, so a file's dependencies
        // are all visible at the top of it.
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => false,
            'import_functions' => false,
        ],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        // An import left behind by a deleted call site is invisible in
        // review and silently wrong in a file's dependency list — the
        // sibling projects in php-systems-lab enforce this one too.
        'no_unused_imports' => true,
        // `in_array($x, $ys)` without the strict flag compares loosely,
        // which for the mixed-type row values this engine passes around
        // (0 == 'a' was true before PHP 8, '1' == 1 still is) is never
        // what is meant. Every call here already passes `true`; the rule
        // is what keeps the next one from forgetting.
        'strict_param' => true,
        // PER-CS wants `fn(`; this codebase and its sibling projects write
        // `fn (`, so the existing spelling wins over the preset.
        'function_declaration' => ['closure_fn_spacing' => 'one'],
        // PER-CS collapses an empty body to `) {}`. A constructor here is
        // usually nothing BUT promoted properties, and `) {` with the brace
        // on its own line keeps the parameter list reading as the body it
        // effectively is.
        'single_line_empty_body' => false,
        // Adding an argument to a multiline call should touch one line,
        // not two.
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
    ])
    ->setFinder($finder);