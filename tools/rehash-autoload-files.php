<?php

/*
 * Give the scoped package's Composer "files" entries identifiers of their own.
 *
 * Composer includes an autoloaded file (guzzle's functions, symfony's
 * trigger_deprecation...) once per process, keyed by an identifier derived from
 * the package name and path: $GLOBALS['__composer_autoload_files'][$id]. Any
 * other module bundling the same package uses the same identifier, so when its
 * autoloader runs first ours skips the file. Unscoped that was harmless (same
 * functions); scoped, our copy declares EmmcpVendor\trigger_deprecation & co,
 * and skipping it leaves them undefined.
 *
 * Usage: php tools/rehash-autoload-files.php <package dir> <prefix>
 *
 * Polyfill bootstraps are left alone: they stay global on purpose, so sharing
 * their identifier with another copy is exactly right.
 */

if ($argc !== 3) {
	fwrite(STDERR, "Usage: php rehash-autoload-files.php <package dir> <prefix>\n");
	exit(2);
}

$composerDir = rtrim($argv[1], '/').'/vendor/composer';
$prefix = $argv[2];

$filesMap = $composerDir.'/autoload_files.php';
if (!is_file($filesMap)) {
	echo "no autoload_files.php, nothing to do\n";
	exit(0);
}

$entries = require $filesMap;
$renames = array();
foreach ($entries as $id => $path) {
	if (strpos(str_replace('\\', '/', $path), '/symfony/polyfill-') !== false) {
		continue;
	}
	$renames[$id] = md5($prefix.':'.$id);
}

foreach (array('autoload_files.php', 'autoload_static.php') as $name) {
	$file = $composerDir.'/'.$name;
	if (!is_file($file)) {
		continue;
	}
	$code = file_get_contents($file);
	foreach ($renames as $old => $new) {
		$count = 0;
		$code = str_replace("'".$old."'", "'".$new."'", $code, $count);
		if ($count !== 1) {
			fwrite(STDERR, "$name: expected exactly one occurrence of $old, found $count\n");
			exit(1);
		}
	}
	file_put_contents($file, $code);
}

echo '  rehashed '.count($renames)." autoloaded file id(s)\n";
