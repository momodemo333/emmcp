<?php
/* Copyright (C) 2026 E-dem
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    tools/normalize-vendor.php
 * \ingroup emmcp
 * \brief   Make a freshly installed vendor/ tree byte-reproducible.
 *
 * Composer downloads and installs packages in parallel, so the order in which
 * they land in vendor/composer/installed.json depends on which download
 * finished first. The generated autoloaders inherit that order, and for a
 * namespace served by several packages — phpDocumentor\Reflection\ comes from
 * reflection-common, type-resolver and reflection-docblock — the PSR-4 path
 * list comes out in a different order on each machine.
 *
 * The result: two clean clones at identical commits and identical locks
 * produced different ZIPs, so the published checksum said nothing about the
 * contents.
 *
 * Rather than rewriting generated files, this sorts the *source* Composer
 * derives them from and lets Composer regenerate. The caller runs
 * `composer dump-autoload` afterwards.
 *
 * Usage: php tools/normalize-vendor.php <path-to-package-root>
 */

$root = $argv[1] ?? '';
if ($root === '' || !is_dir($root)) {
	fwrite(STDERR, "usage: normalize-vendor.php <package-root>\n");
	exit(2);
}

$installed = rtrim($root, '/').'/vendor/composer/installed.json';
if (!is_file($installed)) {
	fwrite(STDERR, "not found: $installed\n");
	exit(2);
}

$raw = file_get_contents($installed);
$data = json_decode((string) $raw, true);
if (!is_array($data) || !isset($data['packages']) || !is_array($data['packages'])) {
	fwrite(STDERR, "unexpected installed.json structure\n");
	exit(2);
}

// Sort packages by name. Composer reads this file in order when building the
// autoload maps, so a stable order here gives stable generated files.
usort($data['packages'], static function (array $a, array $b): int {
	return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});

if (isset($data['dev-package-names']) && is_array($data['dev-package-names'])) {
	sort($data['dev-package-names'], SORT_STRING);
}

$encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($encoded === false) {
	fwrite(STDERR, "could not re-encode installed.json\n");
	exit(1);
}

file_put_contents($installed, $encoded."\n");

fwrite(STDOUT, "  normalised ".count($data['packages'])." package entries\n");
exit(0);
