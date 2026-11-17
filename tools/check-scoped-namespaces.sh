#!/usr/bin/env bash
# Every namespace declared under <package>/vendor must carry the scope prefix,
# or be one left unprefixed on purpose (see tools/scoper.inc.php). Catches a
# too-broad exclusion: php-scoper 0.18.17 spared NeuronAI\MCP\ in Dalfred
# because a plain 'Mcp' exclusion matched any segment. Such a leak would
# otherwise only show on a customer install, as a class silently missing from
# the authoritative classmap or as the very collision scoping exists to prevent.
#
# Usage: tools/check-scoped-namespaces.sh <package dir> <prefix>
set -euo pipefail

dir="${1:?package dir}"
prefix="${2:?prefix}"
test -d "$dir/vendor" || { echo "ERROR: $dir/vendor not found" >&2; exit 1; }

leaked=$(grep -rhoE --include='*.php' '^namespace [A-Za-z_][A-Za-z0-9_]*(\\[A-Za-z_][A-Za-z0-9_]*)*\s*[;{]' "$dir/vendor" \
	| sed -E 's/^namespace //; s/\s*[;{]$//' | sort -u \
	| grep -vE "^($prefix|Mcp|DolibarrMcp|Symfony\\\\Polyfill|Composer)(\\\\|$)" || true)
if [ -n "$leaked" ]; then
	echo "ERROR: namespaces left unprefixed in $dir/vendor:" >&2
	echo "$leaked" >&2
	exit 1
fi
count=$(grep -rhoE --include='*.php' "^namespace $prefix\\\\" "$dir/vendor" | wc -l)
if [ "$count" -eq 0 ]; then
	echo "ERROR: no namespace under $prefix\\ in $dir/vendor: was the package scoped at all?" >&2
	exit 1
fi
echo "  every vendor namespace carries $prefix\\ or is shared on purpose ($count prefixed declarations)"
