<?php
/* Copyright (C) 2026 Morgan Demoulin <support@e-dem.com>
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
 * \file    class/EmmcpEmpaymentTools.php
 * \ingroup emmcp
 * \brief   MCP tool empayment_import_statement: a statement read by the AI becomes EMPayment movements.
 *
 * The AI reads a card or bank statement (PDF, CSV, photo) and sends its lines;
 * EMPayment (2.7.0+) creates the movements, unmatched, for the human to
 * reconcile in its CODA screens. Two steps, as every EMPayment action: a
 * review that writes nothing, then the import with the fingerprint the review
 * returned (contract: empayment docs/API.md, "Import de relevés").
 *
 * The tool goes through EMPayment's REST routes with the API key and entity of
 * the MCP session, so EMPayment's rights, entity and duplicate rules apply
 * exactly as for any other client. It does not use the runtime's REST client:
 * that one keeps only the first 300 characters of an error message, and a
 * refused statement lists every faulty line in error.errors[], which the AI
 * needs in full to correct its reading.
 */

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

class EmmcpEmpaymentTools
{
	/** First EMPayment version that has the statement import routes. */
	const MIN_EMPAYMENT_VERSION = '2.7.0';

	/** Seconds the import may take (up to 1 000 lines and their bank lines). */
	const RESPONSE_TIMEOUT = 120;

	/** @var string */
	private $apiBaseUrl;

	/** @var string */
	private $apiKey;

	/** @var int|null */
	private $entity;

	/** @var callable(string, string, array<int, string>): array{http_code:int, content:string, error:string} */
	private $transport;

	/**
	 * @param string        $apiBaseUrl Dolibarr URL root (DOL_MAIN_URL_ROOT)
	 * @param string        $apiKey     REST API key of the MCP session's user
	 * @param int|null      $entity     Entity of the MCP session (Multicompany), null = user's main entity
	 * @param callable|null $transport  fn(url, jsonBody, headers) => [http_code, content, error]; tests only
	 */
	public function __construct($apiBaseUrl, $apiKey, $entity = null, $transport = null)
	{
		$this->apiBaseUrl = rtrim((string) $apiBaseUrl, '/');
		$this->apiKey = (string) $apiKey;
		$this->entity = $entity !== null ? (int) $entity : null;
		$this->transport = $transport ?: array(self::class, 'httpPost');
	}

	/**
	 * Is the tool usable here: EMPayment enabled in the current entity, at a
	 * version that has the routes, and the user may at least run the review.
	 *
	 * @param DoliDB      $db      Database handler
	 * @param User        $user    User of the MCP session, rights loaded
	 * @param string|null $version EMPayment version, null = read from its descriptor (tests pass one)
	 * @return bool
	 */
	public static function isAvailable($db, $user, $version = null)
	{
		if (!isModEnabled('empayment') || !is_object($user) || empty($user->id)) {
			return false;
		}
		$version = $version !== null ? (string) $version : self::empaymentVersion($db);
		if ($version === '' || version_compare($version, self::MIN_EMPAYMENT_VERSION, '<')) {
			return false;
		}

		return self::userHasRight($user, 'empayment', 'coda_read') && self::userHasRight($user, 'banque', 'lire');
	}

	/**
	 * Version of the EMPayment code installed (its descriptor), '' when absent.
	 *
	 * @param DoliDB $db Database handler
	 * @return string
	 */
	public static function empaymentVersion($db)
	{
		if (!class_exists('modEMPayment')) {
			dol_include_once('/empayment/core/modules/modEMPayment.class.php');
		}
		if (!class_exists('modEMPayment')) {
			return '';
		}
		$module = new modEMPayment($db);

		return (string) $module->version;
	}

	#[McpTool(
		name: 'empayment_import_statement',
		description: <<<'DESC'
Import a bank or credit card statement read by you (PDF, CSV, photo) into EMPayment: each line becomes a movement to reconcile in EMPayment's CODA screens. Nothing is reconciled or recorded by this tool. Two calls, always:

1. REVIEW (confirm=false, the default) writes nothing. It returns the new lines, duplicates, probable duplicates, the balance check, warnings and an intent_fingerprint. ALWAYS show the user this review (number of new lines, duplicates, probable duplicates, balance check, warnings) and wait for their explicit approval.
2. IMPORT (confirm=true + intent_fingerprint from the review) with EXACTLY the same arguments as the review. Only after the user approved.

Rules:
- bank_account_id: the Dolibarr bank account the user names or confirms (for a card statement, the account dedicated to that card). Never guess it from an IBAN, a card number or a name: if unsure, list the accounts (dolibarr_list resource "bankaccounts") and ask the user.
- Sign every amount from the account's point of view: a card purchase or a debit is NEGATIVE ("-55.03"), a refund, a credit or the monthly repayment of the card is positive. Amounts are strings with a dot and at most 2 decimals, never JSON numbers.
- Copy the transaction number printed on the statement into external_id whenever there is one: it is the duplicate key.
- Give opening_balance and closing_balance whenever the statement prints them (both or neither): they check your reading. A balance mismatch means a line was misread, missed or signed wrong: re-read the statement, never adjust a balance or a line to make the check pass.
- Sending the same statement again creates nothing (status already_imported, or the lines reported as duplicate). Never alter a line (amount, date, text, external_id) to get round a reported duplicate: a duplicate means the movement already exists. Show probable duplicates to the user instead.
- A 409 (STALE_INTENT, CONCURRENT_IMPORT) or a 503: run the REVIEW again and show it again; never repeat the import call blindly.
DESC,
		annotations: new ToolAnnotations(title: 'Import a statement into EMPayment', readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
	)]
	public function importStatement(
		#[Schema(description: 'Id of the Dolibarr bank account the movements belong to, as named or confirmed by the user. Never guessed.', minimum: 1)]
		int $bank_account_id,
		#[Schema(description: 'Reference of the statement, 1 to 20 characters among A-Z a-z 0-9 - / . (e.g. "VISA-2026-11"). Becomes the statement number of the bank lines.', minLength: 1, maxLength: 20)]
		string $statement_ref,
		#[Schema(description: '"card" for a credit card statement (bank lines in payment mode CB), "account" for a bank account statement (VIR).', enum: array('card', 'account'))]
		string $kind,
		#[Schema(
			description: 'The statement lines, 1 to 1000, in the order of the statement.',
			minItems: 1,
			maxItems: 1000,
			items: array(
				'type' => 'object',
				'properties' => array(
					'date' => array('type' => 'string', 'description' => 'Transaction date, YYYY-MM-DD.'),
					'value_date' => array('type' => 'string', 'description' => 'Value or posting date, YYYY-MM-DD, when printed.'),
					'amount' => array('type' => 'string', 'description' => 'Signed decimal STRING from the account\'s point of view: card purchase "-55.03", repayment "412.30". Dot, at most 2 decimals, never zero.'),
					'counterparty' => array('type' => 'string', 'description' => 'Merchant or counterparty as printed (at least one of counterparty / communication).'),
					'communication' => array('type' => 'string', 'description' => 'Description or communication as printed.'),
					'external_id' => array('type' => 'string', 'description' => 'Transaction number printed on the statement. Copy it whenever there is one: it is the duplicate key. Unique within the statement.'),
					'original_amount' => array('type' => 'string', 'description' => 'Amount in the original currency, free text (e.g. "49.99 USD").'),
				),
				'required' => array('date', 'amount'),
			),
		)]
		array $lines,
		#[Schema(description: 'false (default) = REVIEW, writes nothing. true = IMPORT, only after the user approved the review, with the same arguments and the intent_fingerprint of that review.')]
		bool $confirm = false,
		#[Schema(description: 'intent_fingerprint returned by the review. Required when confirm is true.')]
		?string $intent_fingerprint = null,
		#[Schema(description: 'Name of the document read (e.g. "Relevé VISA novembre 2026.pdf"), at most 200 characters.')]
		?string $source_label = null,
		#[Schema(description: 'First day of the statement period, YYYY-MM-DD (with period_to). Every line must then be within the period.')]
		?string $period_from = null,
		#[Schema(description: 'Last day of the statement period, YYYY-MM-DD (with period_from).')]
		?string $period_to = null,
		#[Schema(description: 'ISO 4217 currency of the statement (e.g. "EUR"); must be the currency of the account.')]
		?string $currency = null,
		#[Schema(description: 'Balance of the account before the statement, signed decimal string, as printed (give both balances or neither).')]
		?string $opening_balance = null,
		#[Schema(description: 'Balance of the account after the statement, signed decimal string, as printed.')]
		?string $closing_balance = null
	): string {
		$body = array(
			'bank_account_id' => $bank_account_id,
			'statement_ref' => $statement_ref,
			'kind' => $kind,
		);
		foreach (array('source_label' => $source_label, 'currency' => $currency, 'opening_balance' => $opening_balance, 'closing_balance' => $closing_balance) as $field => $value) {
			if ($value !== null && $value !== '') {
				$body[$field] = $value;
			}
		}
		if (($period_from !== null && $period_from !== '') || ($period_to !== null && $period_to !== '')) {
			$body['period'] = array('from' => (string) $period_from, 'to' => (string) $period_to);
		}
		$body['lines'] = array_values($lines);

		if ($confirm) {
			if ($intent_fingerprint === null || trim($intent_fingerprint) === '') {
				throw new ToolCallException(self::encode(array(
					'error' => 'FINGERPRINT_REQUIRED',
					'what_to_do' => 'Run the review first (confirm=false), show it to the user, and once they approve call again with the same arguments, confirm=true and the intent_fingerprint of that review.',
				)));
			}
			$body['confirm'] = true;
			$body['intent_fingerprint'] = $intent_fingerprint;
			$route = 'empayment/statements';
		} else {
			$route = 'empayment/statements/review';
		}

		$answer = $this->post($route, $body);
		$decoded = json_decode($answer['content'], true);

		if ($answer['error'] !== '' || $answer['http_code'] === 0) {
			throw new ToolCallException(self::encode(array(
				'error' => 'EMPAYMENT_UNREACHABLE',
				'detail' => $answer['error'] !== '' ? $answer['error'] : 'no answer',
				'what_to_do' => $confirm
					? 'The import may or may not have been written. Do NOT repeat the import: run the review again (confirm=false); a statement already imported answers already_imported.'
					: 'Try the review again later, or tell the user EMPayment could not be reached.',
			)));
		}

		if ($answer['http_code'] >= 200 && $answer['http_code'] < 300 && is_array($decoded)) {
			return self::encode(array('http_status' => $answer['http_code']) + $decoded + array('next_step' => $this->nextStep($decoded, $confirm)));
		}

		throw new ToolCallException(self::encode(array(
			'http_status' => $answer['http_code'],
			'error' => is_array($decoded) && isset($decoded['error']) ? $decoded['error'] : substr((string) $answer['content'], 0, 500),
			'what_to_do' => self::whatToDo($answer['http_code'], is_array($decoded) ? $decoded : array(), $confirm),
		)));
	}

	/**
	 * Guidance after a successful answer.
	 *
	 * @param array $decoded Answer of EMPayment
	 * @param bool  $confirm Import (true) or review (false)
	 * @return string
	 */
	private function nextStep(array $decoded, $confirm)
	{
		$status = isset($decoded['status']) ? (string) $decoded['status'] : '';
		if ($status === 'already_imported') {
			return 'Nothing to import: every line of this statement already exists in EMPayment. Tell the user; do not change any line to force an import.';
		}
		if (!$confirm) {
			return 'Show the user this review: counts (new, duplicates, probable_duplicates), balance_check, dolibarr_balance and warnings, and the lines flagged as probable duplicates. Only after their explicit approval, call empayment_import_statement again with exactly the same arguments, confirm=true and intent_fingerprint="'.(isset($decoded['intent_fingerprint']) ? (string) $decoded['intent_fingerprint'] : '').'".';
		}
		$screen = isset($decoded['screen_url']) ? $this->absoluteUrl((string) $decoded['screen_url']) : '';

		return 'Imported. Tell the user how many movements were created'.($screen !== '' ? ' and give them this link to review them: '.$screen : '').'. They reconcile them in EMPayment (CODA, Classer / rapprocher); nothing was matched or recorded.';
	}

	/**
	 * Guidance after a refusal.
	 *
	 * @param int   $httpCode HTTP status
	 * @param array $decoded  Error body
	 * @param bool  $confirm  Import (true) or review (false)
	 * @return string
	 */
	private static function whatToDo($httpCode, array $decoded, $confirm)
	{
		$codes = array();
		if (isset($decoded['error']['errors']) && is_array($decoded['error']['errors'])) {
			foreach ($decoded['error']['errors'] as $e) {
				if (is_array($e) && isset($e['code'])) {
					$codes[] = (string) $e['code'];
				}
			}
		}

		switch ((int) $httpCode) {
			case 400:
				return 'The statement was refused as a whole, nothing was written. error.errors lists EVERY faulty line (index = position in lines). Re-read those lines on the statement and fix the reading (dates YYYY-MM-DD, amounts as signed strings with a dot, a counterparty or communication on each line, unique external_id), then run the review again.';
			case 401:
				return 'Authentication refused: the user must reconnect the AI client.';
			case 403:
				return $confirm
					? 'The user lacks a right to import: EMPayment "Import CODA files" (coda_setup) and Banks "Create/modify" are both needed. Ask an administrator.'
					: 'The user lacks a right to review: EMPayment "View CODA transactions" (coda_read) and Banks "Read" are both needed. Ask an administrator.';
			case 404:
				return 'No bank account with this id in this Dolibarr (and entity). Ask the user which account to use (dolibarr_list resource "bankaccounts"); never guess it.';
			case 409:
				return 'The situation changed since the review (a line was imported meanwhile, or another import is running). Run the review again (confirm=false), show it again to the user, and import only with the new intent_fingerprint.';
			case 422:
				if (in_array('BALANCE_MISMATCH', $codes, true)) {
					return 'The lines do not add up to the difference between the balances: a line was misread, missed, or signed wrong. Re-read the statement and correct the reading; never adjust a balance or a line just to make the check pass. Tell the user if you cannot find the gap.';
				}
				if (in_array('CURRENCY_MISMATCH', $codes, true)) {
					return 'The statement currency is not the currency of this account. Ask the user which account to use.';
				}
				if (in_array('ACCOUNT_CLOSED', $codes, true)) {
					return 'This bank account is closed. Ask the user which account to use.';
				}
				return 'The statement cannot be imported on this account; tell the user the reason given in error.';
			case 503:
				return 'EMPayment could not complete the operation. Do NOT repeat the import call: run the review again (confirm=false); a statement already imported answers already_imported.';
		}

		return 'Unexpected answer from EMPayment: tell the user the error.';
	}

	/**
	 * POST a JSON body to an EMPayment REST route of this Dolibarr.
	 *
	 * @param string $route API route (no leading slash)
	 * @param array  $body  Request body
	 * @return array{http_code:int, content:string, error:string}
	 */
	private function post($route, array $body)
	{
		$headers = array(
			'DOLAPIKEY: '.$this->apiKey,
			'Content-Type: application/json',
			'Accept: application/json',
		);
		if ($this->entity !== null) {
			$headers[] = 'DOLAPIENTITY: '.$this->entity;
		}
		$json = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

		$answer = call_user_func($this->transport, $this->apiBaseUrl.'/api/index.php/'.$route, $json, $headers);

		// Behind a TLS-terminating proxy that does not say so (no
		// X-Forwarded-Proto), Dolibarr believes it is served over http and the
		// proxy redirects the call to https. Dolibarr's HTTP client does not
		// follow a 308 (nor any redirect of a POST on Dolibarr 17), so retry
		// once, same host and path, over https: the key never leaves the host.
		if (in_array((int) $answer['http_code'], array(301, 302, 307, 308), true) && strncmp($this->apiBaseUrl, 'http://', 7) === 0) {
			$this->apiBaseUrl = 'https://'.substr($this->apiBaseUrl, 7);
			$answer = call_user_func($this->transport, $this->apiBaseUrl.'/api/index.php/'.$route, $json, $headers);
		}

		return $answer;
	}

	/**
	 * Default transport: Dolibarr's own HTTP client.
	 *
	 * @param string             $url     URL
	 * @param string             $json    Body
	 * @param array<int, string> $headers Headers
	 * @return array{http_code:int, content:string, error:string}
	 */
	public static function httpPost($url, $json, array $headers)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';

		// An import of up to 1 000 lines with their bank lines may take longer
		// than Dolibarr's default 30 s response timeout.
		$previous = isset($conf->global->MAIN_USE_RESPONSE_TIMEOUT) ? $conf->global->MAIN_USE_RESPONSE_TIMEOUT : null;
		if ((int) $previous < self::RESPONSE_TIMEOUT) {
			$conf->global->MAIN_USE_RESPONSE_TIMEOUT = self::RESPONSE_TIMEOUT;
		}
		try {
			$r = getURLContent($url, 'POSTALREADYFORMATED', $json, 0, $headers, array('http', 'https'), 2, -1);
		} finally {
			if ($previous === null) {
				unset($conf->global->MAIN_USE_RESPONSE_TIMEOUT);
			} else {
				$conf->global->MAIN_USE_RESPONSE_TIMEOUT = $previous;
			}
		}

		return array(
			'http_code' => isset($r['http_code']) ? (int) $r['http_code'] : 0,
			'content' => isset($r['content']) ? (string) $r['content'] : '',
			'error' => !empty($r['curl_error_no']) ? (string) $r['curl_error_msg'] : '',
		);
	}

	/**
	 * screen_url is a path from the web root: make it a link the user can open.
	 *
	 * @param string $path Path or URL
	 * @return string
	 */
	private function absoluteUrl($path)
	{
		if ($path === '' || preg_match('#^https?://#i', $path)) {
			return $path;
		}
		$parts = parse_url($this->apiBaseUrl);
		if (empty($parts['scheme']) || empty($parts['host'])) {
			return $path;
		}

		return $parts['scheme'].'://'.$parts['host'].(!empty($parts['port']) ? ':'.$parts['port'] : '').'/'.ltrim($path, '/');
	}

	/**
	 * @param User   $user   User
	 * @param string $module Rights module
	 * @param string $perm   Right
	 * @return bool
	 */
	private static function userHasRight($user, $module, $perm)
	{
		if (method_exists($user, 'hasRight')) {
			return (bool) $user->hasRight($module, $perm);
		}

		return !empty($user->rights->$module->$perm);
	}

	/**
	 * @param array $data Data
	 * @return string
	 */
	private static function encode(array $data)
	{
		return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}
}
