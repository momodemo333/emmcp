<?php

/*
 * php-scoper configuration for the embedded MCP runtime (emMCP build only).
 *
 * Why: Dolibarr loads every third-party library into one PHP process, and many
 * of them ship their own copy of psr/log, psr/container, guzzle, symfony/*...
 * Two copies of one class cannot coexist: whichever loads first wins, and when
 * the other copy's classes are written against a different major version PHP
 * fails with a fatal error. Seen in production (2026-11): a psr/log 1.x
 * LoggerInterface loaded by something else, then our psr/log 3 NullLogger
 * implementing it -> "Declaration of Psr\Log\NullLogger::log(...) must be
 * compatible with Psr\Log\LoggerInterface::log(...)" on every MCP call.
 *
 * Every third-party namespace is therefore moved under EmmcpVendor\ in the
 * package we ship, so nothing another module or the core loads can collide
 * with it. Two namespaces stay as they are, on purpose:
 *  - DolibarrMcp\  the runtime's own code, and the API other modules implement
 *                  (DolibarrMcp\Extension\ToolExtension, SqlCapabilityInterface);
 *  - Mcp\          the MCP SDK: extension modules declare their tools with its
 *                  attributes (#[Mcp\Capability\Attribute\McpTool]); scoped,
 *                  those attributes would no longer be recognised.
 * Their references to Psr\, GuzzleHttp\, Symfony\... are still rewritten.
 *
 * Polyfills keep their global functions (uuid_create, str_contains...), which
 * they only declare when missing, so they cannot collide either.
 */

declare(strict_types=1);

return [
    'prefix' => 'EmmcpVendor',

    // Anchored regexes, not plain names: php-scoper 0.18.17 matches a plain
    // 'Mcp' against ANY namespace segment, case-insensitively, so a dependency
    // namespace such as Foo\MCP\ would be left unprefixed (seen in Dalfred
    // with NeuronAI\MCP\). The build also checks that no other namespace
    // under vendor/ escaped the prefix (tools/check-scoped-namespaces.sh).
    'exclude-namespaces' => array_map(
        static fn (string $ns): string => '~^'.preg_quote($ns, '~').'(\\\\|$)~',
        ['DolibarrMcp', 'Mcp', 'Symfony\\Polyfill']
    ),

    // Polyfill entry points declare global functions/classes guarded by
    // function_exists()/class_exists(); scoping them would only break that guard.
    // EMMCP_SCOPE_SRC is the package directory being scoped (set by the Makefile).
    'exclude-files' => array_merge(
        glob(rtrim((string) getenv('EMMCP_SCOPE_SRC'), '/').'/vendor/symfony/polyfill-*/bootstrap*.php') ?: [],
        glob(rtrim((string) getenv('EMMCP_SCOPE_SRC'), '/').'/vendor/symfony/polyfill-*/Resources/stubs/*.php') ?: []
    ),

    'expose-global-constants' => false,
    'expose-global-classes' => false,
    'expose-global-functions' => false,
];
