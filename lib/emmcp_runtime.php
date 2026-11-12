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
 * \file    lib/emmcp_runtime.php
 * \ingroup emmcp
 * \brief   Guard around the embedded MCP runtime: shared-library collisions and fatal errors.
 *
 * Dolibarr runs every module in one PHP process, and many modules (and the
 * core itself, under includes/) ship their own copy of psr/log, guzzle,
 * symfony... PHP cannot hold two classes of the same name: the first copy
 * loaded wins, and when the other copy was written against a different major
 * version the result is a fatal error, an opaque HTTP 500 on every MCP call.
 *
 * The released package removes the cause: its third-party dependencies are
 * prefixed (EmmcpVendor\, see tools/scoper.inc.php), so nothing another module
 * loads can be mistaken for them. What remains shared on purpose are the
 * runtime's own namespaces (DolibarrMcp\, Mcp\), which other modules extend.
 *
 * This file covers what prefixing cannot: an unprefixed runtime (development
 * checkout, Dalfred's copy), and a module that ships its own DolibarrMcp\ or
 * Mcp\ classes. Before the runtime is used, each key type is checked to come
 * from the runtime's own directory; a type found elsewhere is reported with
 * the file that defined it, as a JSON-RPC error and on the Diagnostic tab,
 * instead of a fatal error. A fatal error that still happens is recorded the
 * same way by a shutdown handler.
 */

/**
 * Key types the runtime cannot work without, interfaces before the classes
 * implementing them: checking an implementation first would load it, and a
 * foreign interface would turn that into the very fatal error this avoids.
 *
 * Third-party names are given unprefixed; emmcp_runtime_class() adds the
 * prefix when the runtime in use is scoped.
 *
 * @return array{own: string[], vendor: string[]}
 */
function emmcp_runtime_key_types()
{
	return array(
		'own' => array(
			'DolibarrMcp\Bootstrap',
			'DolibarrMcp\Extension\ToolExtension',
			'Mcp\Server',
			'Mcp\Server\Builder',
			'Mcp\Capability\Attribute\McpTool',
		),
		'vendor' => array(
			'Psr\Log\LoggerInterface',
			'Psr\Log\NullLogger',
			'Psr\Container\ContainerInterface',
			'Psr\EventDispatcher\EventDispatcherInterface',
			'Psr\Clock\ClockInterface',
			'Psr\Http\Message\MessageInterface',
			'Psr\Http\Message\RequestInterface',
			'Psr\Http\Message\ServerRequestInterface',
			'Psr\Http\Message\ResponseInterface',
			'Psr\Http\Message\StreamInterface',
			'Psr\Http\Message\ResponseFactoryInterface',
			'Psr\Http\Message\StreamFactoryInterface',
			'Psr\Http\Server\RequestHandlerInterface',
			'Psr\Http\Server\MiddlewareInterface',
			'GuzzleHttp\Psr7\ServerRequest',
			'GuzzleHttp\Psr7\HttpFactory',
			'GuzzleHttp\Psr7\Utils',
			'Symfony\Component\Uid\Uuid',
			'Opis\JsonSchema\Validator',
		),
	);
}

/**
 * Namespace prefix of the runtime's third-party dependencies, '' when they
 * are not prefixed. Written by the build next to the Composer autoloader.
 *
 * @param string $packageDir Runtime package directory (holds vendor/)
 * @return string
 */
function emmcp_runtime_prefix($packageDir)
{
	static $cache = array();
	if (!array_key_exists($packageDir, $cache)) {
		$marker = rtrim($packageDir, '/').'/vendor/emmcp-scope-prefix.txt';
		$prefix = is_file($marker) ? trim((string) file_get_contents($marker)) : '';
		$cache[$packageDir] = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $prefix) ? $prefix : '';
	}

	return $cache[$packageDir];
}

/**
 * Real name of a third-party class in the runtime in use.
 *
 * @param string $packageDir Runtime package directory
 * @param string $class      Unprefixed class name, e.g. GuzzleHttp\Psr7\ServerRequest
 * @return string
 */
function emmcp_runtime_class($packageDir, $class)
{
	$prefix = emmcp_runtime_prefix($packageDir);

	return $prefix === '' ? ltrim($class, '\\') : $prefix.'\\'.ltrim($class, '\\');
}

/**
 * Runtime package directory from the path of its Composer autoloader.
 *
 * @param string $autoload .../dolibarr-mcp-server/vendor/autoload.php
 * @return string
 */
function emmcp_runtime_package_dir($autoload)
{
	return dirname(dirname($autoload));
}

/**
 * Key types already defined, or resolved, from outside the runtime directory.
 *
 * With $load false only what is already declared is inspected, and nothing is
 * loaded: safe anywhere, including an admin page. With $load true the missing
 * types are loaded through the autoloaders in place, which is how a foreign
 * autoloader answering before ours shows up; call it only once the runtime's
 * autoloader is registered.
 *
 * @param string $packageDir Runtime package directory
 * @param bool   $load       Load the types not declared yet
 * @return array<int, array{type: string, file: string}> Empty when all is well
 */
function emmcp_runtime_conflicts($packageDir, $load)
{
	$root = realpath($packageDir);
	if ($root === false) {
		return array();
	}
	$root = rtrim(str_replace('\\', '/', $root), '/').'/';

	$types = emmcp_runtime_key_types();
	$names = $types['own'];
	foreach ($types['vendor'] as $class) {
		$names[] = emmcp_runtime_class($packageDir, $class);
	}

	$conflicts = array();
	foreach ($names as $name) {
		$declared = class_exists($name, false) || interface_exists($name, false) || trait_exists($name, false);
		if (!$declared && $load) {
			$declared = class_exists($name) || interface_exists($name);
		}
		if (!$declared) {
			continue;
		}
		$file = (new ReflectionClass($name))->getFileName();
		if ($file === false) {
			continue;
		}
		$real = realpath($file);
		$real = str_replace('\\', '/', $real !== false ? $real : $file);
		if (strpos($real, $root) !== 0) {
			$conflicts[] = array('type' => $name, 'file' => $real);
			if ($load) {
				// Loading further would only reach the fatal error.
				break;
			}
		}
	}

	return $conflicts;
}

/**
 * A path made readable and harmless to show: relative to the Dolibarr root
 * when it lives under it.
 *
 * @param string $file Absolute path
 * @return string
 */
function emmcp_runtime_short_path($file)
{
	$file = str_replace('\\', '/', (string) $file);
	foreach (array(DOL_DOCUMENT_ROOT, dirname(DOL_DOCUMENT_ROOT)) as $base) {
		$base = rtrim(str_replace('\\', '/', (string) $base), '/').'/';
		if ($base !== '/' && strpos($file, $base) === 0) {
			return substr($file, strlen($base));
		}
	}

	return $file;
}

/**
 * One sentence describing a conflict, for the client and the log.
 *
 * @param array{type: string, file: string} $conflict Conflict
 * @return string
 */
function emmcp_runtime_conflict_message($conflict)
{
	return 'Dependency conflict: '.$conflict['type'].' is already loaded from '
		.emmcp_runtime_short_path($conflict['file'])
		.' (another module or library ships an incompatible copy). See emMCP setup > Diagnostic.';
}

/**
 * Where the last runtime failure is kept for the Diagnostic tab.
 *
 * @return string
 */
function emmcp_runtime_error_file()
{
	return DOL_DATA_ROOT.'/emmcp/diagnostics/runtime-error.json';
}

/**
 * Remember a runtime failure (overwrites the previous one).
 *
 * @param string $message What happened
 * @param string $file    File involved, if any
 * @param int    $line    Line, if any
 * @return void
 */
function emmcp_runtime_record_error($message, $file = '', $line = 0)
{
	$path = emmcp_runtime_error_file();
	$dir = dirname($path);
	if (!is_dir($dir)) {
		@mkdir($dir, 0775, true);
	}
	@file_put_contents($path, json_encode(array(
		'date' => time(),
		'message' => substr((string) $message, 0, 2000),
		'file' => $file !== '' ? emmcp_runtime_short_path($file) : '',
		'line' => (int) $line,
		'php' => PHP_VERSION,
		'dolibarr' => defined('DOL_VERSION') ? DOL_VERSION : '',
	)));
}

/**
 * Last recorded runtime failure, or null.
 *
 * @return array|null
 */
function emmcp_runtime_last_error()
{
	$path = emmcp_runtime_error_file();
	if (!is_file($path)) {
		return null;
	}
	$data = json_decode((string) file_get_contents($path), true);

	return is_array($data) ? $data : null;
}

/**
 * Forget the recorded failure (a request went through, or the admin cleared it).
 *
 * @return void
 */
function emmcp_runtime_clear_error()
{
	$path = emmcp_runtime_error_file();
	if (is_file($path)) {
		@unlink($path);
	}
}

/**
 * Turn a fatal error during the MCP request into a recorded, readable failure.
 *
 * PHP cannot catch "Declaration of X must be compatible with Y" or a class
 * declared twice: the request dies. This handler at least records what killed
 * it for the Diagnostic tab and the connection log, and answers JSON-RPC when
 * nothing was sent yet. emmcp_runtime_disarm() ends its watch once the
 * response is out.
 *
 * @return void
 */
function emmcp_runtime_arm_fatal_handler()
{
	$GLOBALS['emmcp_runtime_armed'] = true;
	register_shutdown_function(function () {
		if (empty($GLOBALS['emmcp_runtime_armed'])) {
			return;
		}
		$err = error_get_last();
		$fatal = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR);
		if (!$err || !in_array($err['type'], $fatal, true)) {
			return;
		}

		emmcp_runtime_record_error($err['message'], $err['file'], $err['line']);
		if (function_exists('dol_syslog')) {
			dol_syslog('[EMMCP] FATAL '.$err['message'].' in '.$err['file'].':'.$err['line'], LOG_ERR);
		}
		if (!empty($GLOBALS['db']) && class_exists('EmmcpAuthLog')) {
			EmmcpAuthLog::record($GLOBALS['db'], 'mcp_runtime', false, 'PHP fatal error: '.$err['message'], null, $GLOBALS['emmcp_runtime_user'] ?? null);
		}

		if (!headers_sent()) {
			http_response_code(500);
			header('Content-Type: application/json');
			print json_encode(array(
				'jsonrpc' => '2.0',
				'id' => null,
				'error' => array(
					'code' => -32603,
					'message' => 'The MCP runtime stopped on a PHP fatal error. An administrator can read the cause in emMCP setup > Diagnostic.',
				),
			));
		}
	});
}

/**
 * The response is out: a later shutdown is none of the handler's business.
 *
 * @return void
 */
function emmcp_runtime_disarm()
{
	$GLOBALS['emmcp_runtime_armed'] = false;
}
