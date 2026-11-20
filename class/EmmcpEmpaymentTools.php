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
 * \brief   MCP tools empayment_review_statement / empayment_import_statement: a statement read by the AI becomes EMPayment movements.
 *
 * The AI reads a card or bank statement (PDF, CSV, photo) and sends its lines;
 * EMPayment (2.7.0+) creates the movements, unmatched, for the human to
 * reconcile in its CODA screens. Two steps, as every EMPayment action: a
 * review that writes nothing, then the import with the fingerprint the review
 * returned (contract: empayment docs/API.md, "Import de relevés").
 *
 * Two tools, not one with a flag: the review is read-only (a client may let
 * it run without asking), the import is not, so the client's own permission
 * prompt stands between them as a real human barrier. The fingerprint only
 * proves the state did not change, not that a human agreed.
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

	/** Rules shared by both tools, part of each tool description. */
	const RULES = <<<'DESC'
Rules:
- bank_account_id: the Dolibarr bank account the user names or confirms (for a card statement, the account dedicated to that card). Never guess it from an IBAN, a card number or a name: if unsure, list the accounts (dolibarr_list resource "bankaccounts") and ask the user.
- Sign every amount from the account's point of view: a card purchase or a debit is NEGATIVE ("-55.03"), a refund, a credit or the monthly repayment of the card is positive. Amounts are strings with a dot and at most 2 decimals, never JSON numbers.
- opening_balance / closing_balance (both or neither, whenever the statement gives them): signed from the account's point of view too. On a card, an amount OWED is NEGATIVE even when the statement prints it positive: printed "Solde précédent 412,30" owed -> "-412.30". A balance mismatch means a line was misread, missed or signed wrong, or the balances' sign was taken as printed: try the opposite of BOTH balances first; never flip the sign of the lines to pass the check.
- external_id: the issuer's unique id of the transaction (transaction reference, operation number), stable across statements. Never a line number, a counter of the statement or a short authorization code; when in doubt, leave it empty. It is the duplicate key.
- Replaying a statement creates nothing only if its lines are read identically (same external_id, or same date, amount and text). A probable duplicate is almost always a real duplicate (the same movement read differently): show each one to the user. accept_probable_duplicates=true only after the user explicitly agreed, line by line, that those lines are distinct movements.
- Never alter a line (amount, date, text, external_id) to get round a reported duplicate or conflict.
- After a 409 or a 503: run empayment_review_statement again and show it again; never repeat the import blindly.
DESC;

	/** JSON schema of one statement line: closed, as EMPayment refuses unknown keys. */
	const LINE_SCHEMA = array(
		'type' => 'object',
		'properties' => array(
			'date' => array('type' => 'string', 'description' => 'Transaction date, YYYY-MM-DD.'),
			'value_date' => array('type' => 'string', 'description' => 'Value or posting date, YYYY-MM-DD, when printed.'),
			'amount' => array('type' => 'string', 'description' => 'Signed decimal STRING from the account\'s point of view: card purchase "-55.03", repayment "412.30". Dot, at most 2 decimals, never zero.'),
			'counterparty' => array('type' => 'string', 'description' => 'Merchant or counterparty as printed (at least one of counterparty / communication).'),
			'communication' => array('type' => 'string', 'description' => 'Description or communication as printed.'),
			'external_id' => array('type' => 'string', 'description' => 'The issuer\'s unique id of this transaction (transaction reference, operation number). Never a line number, a counter of the statement or a short authorization code; when in doubt, leave it out. Unique within the statement.'),
			'original_amount' => array('type' => 'string', 'description' => 'Amount in the original currency, free text (e.g. "49.99 USD").'),
		),
		'required' => array('date', 'amount'),
		'additionalProperties' => false,
	);

	const DESC_ACCOUNT = 'Id of the Dolibarr bank account the movements belong to, as named or confirmed by the user. Never guessed.';
	const DESC_REF = 'Reference of the statement, 1 to 20 characters among A-Z a-z 0-9 - / . (e.g. "VISA-2026-11"). Becomes the statement number of the bank lines.';
	const DESC_KIND = '"card" for a credit card statement (bank lines in payment mode CB), "account" for a bank account statement (VIR).';
	const DESC_LINES = 'The statement lines, 1 to 1000, in the order of the statement. Only the listed keys are accepted.';
	const DESC_ACCEPT = 'true only after you showed the user every probable duplicate and they explicitly agreed, line by line, that those lines are distinct movements. Default false. Must be the same in the review and the import (it is part of the fingerprint).';
	const DESC_SOURCE = 'Name of the document read (e.g. "Relevé VISA novembre 2026.pdf"), at most 200 characters.';
	const DESC_FROM = 'First day of the statement period, YYYY-MM-DD (with period_to). Every line must then be within the period.';
	const DESC_TO = 'Last day of the statement period, YYYY-MM-DD (with period_from).';
	const DESC_CURRENCY = 'ISO 4217 currency of the statement (e.g. "EUR"); must be the currency of the account.';
	const DESC_OPENING = 'Balance of the account before the statement, signed from the ACCOUNT\'s point of view (give both balances or neither). On a card an amount owed is NEGATIVE even if printed positive: printed "412,30" owed -> "-412.30".';
	const DESC_CLOSING = 'Balance of the account after the statement, signed from the ACCOUNT\'s point of view. On a card an amount owed is NEGATIVE even if printed positive: printed "1 288,95" owed -> "-1288.95".';

	#[McpTool(
		name: 'empayment_review_statement',
		description: "Review a bank or credit card statement read by you (PDF, CSV, photo) before importing it into EMPayment. WRITES NOTHING. Returns the new lines, duplicates, probable duplicates, the balance check, the Dolibarr balance, warnings and an intent_fingerprint.\n\nALWAYS show the user this review (number of new lines, duplicates, probable duplicates, balance check, warnings) and wait for their explicit approval before calling empayment_import_statement with exactly the same arguments and this intent_fingerprint.\n\n".self::RULES,
		annotations: new ToolAnnotations(title: 'Review a statement for EMPayment (no write)', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
	)]
	public function reviewStatement(
		#[Schema(description: self::DESC_ACCOUNT, minimum: 1)]
		int $bank_account_id,
		#[Schema(description: self::DESC_REF, minLength: 1, maxLength: 20)]
		string $statement_ref,
		#[Schema(description: self::DESC_KIND, enum: array('card', 'account'))]
		string $kind,
		#[Schema(description: self::DESC_LINES, minItems: 1, maxItems: 1000, items: self::LINE_SCHEMA)]
		array $lines,
		#[Schema(description: self::DESC_ACCEPT)]
		bool $accept_probable_duplicates = false,
		#[Schema(description: self::DESC_SOURCE)]
		?string $source_label = null,
		#[Schema(description: self::DESC_FROM)]
		?string $period_from = null,
		#[Schema(description: self::DESC_TO)]
		?string $period_to = null,
		#[Schema(description: self::DESC_CURRENCY)]
		?string $currency = null,
		#[Schema(description: self::DESC_OPENING)]
		?string $opening_balance = null,
		#[Schema(description: self::DESC_CLOSING)]
		?string $closing_balance = null
	): string {
		$body = $this->statementBody($bank_account_id, $statement_ref, $kind, $lines, $accept_probable_duplicates, $source_label, $period_from, $period_to, $currency, $opening_balance, $closing_balance);

		return $this->run('empayment/statements/review', $body, false);
	}

	#[McpTool(
		name: 'empayment_import_statement',
		description: "Import into EMPayment a statement you reviewed with empayment_review_statement: each new line becomes a movement to reconcile in EMPayment's CODA screens (nothing is reconciled or recorded). WRITES.\n\nCall it ONLY after the user explicitly approved the review you showed them, with exactly the same arguments as that review and its intent_fingerprint. Never call it in the same turn as the review without that approval.\n\n".self::RULES,
		annotations: new ToolAnnotations(title: 'Import a reviewed statement into EMPayment', readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
	)]
	public function importStatement(
		#[Schema(description: self::DESC_ACCOUNT, minimum: 1)]
		int $bank_account_id,
		#[Schema(description: self::DESC_REF, minLength: 1, maxLength: 20)]
		string $statement_ref,
		#[Schema(description: self::DESC_KIND, enum: array('card', 'account'))]
		string $kind,
		#[Schema(description: self::DESC_LINES, minItems: 1, maxItems: 1000, items: self::LINE_SCHEMA)]
		array $lines,
		#[Schema(description: 'intent_fingerprint returned by the review the user approved.', minLength: 1)]
		string $intent_fingerprint,
		#[Schema(description: self::DESC_ACCEPT)]
		bool $accept_probable_duplicates = false,
		#[Schema(description: self::DESC_SOURCE)]
		?string $source_label = null,
		#[Schema(description: self::DESC_FROM)]
		?string $period_from = null,
		#[Schema(description: self::DESC_TO)]
		?string $period_to = null,
		#[Schema(description: self::DESC_CURRENCY)]
		?string $currency = null,
		#[Schema(description: self::DESC_OPENING)]
		?string $opening_balance = null,
		#[Schema(description: self::DESC_CLOSING)]
		?string $closing_balance = null
	): string {
		if (trim($intent_fingerprint) === '') {
			throw new ToolCallException(self::encode(array(
				'error' => 'FINGERPRINT_REQUIRED',
				'what_to_do' => 'Run empayment_review_statement first, show the review to the user, and once they approve call empayment_import_statement with the same arguments and the intent_fingerprint of that review.',
			)));
		}
		$body = $this->statementBody($bank_account_id, $statement_ref, $kind, $lines, $accept_probable_duplicates, $source_label, $period_from, $period_to, $currency, $opening_balance, $closing_balance);
		$body['confirm'] = true;
		$body['intent_fingerprint'] = $intent_fingerprint;

		return $this->run('empayment/statements', $body, true);
	}

	/**
	 * Request body of both routes, from the tool arguments (absent fields omitted).
	 *
	 * @return array<string, mixed>
	 */
	private function statementBody($bankAccountId, $statementRef, $kind, array $lines, $acceptProbable, $sourceLabel, $periodFrom, $periodTo, $currency, $opening, $closing)
	{
		$body = array(
			'bank_account_id' => (int) $bankAccountId,
			'statement_ref' => (string) $statementRef,
			'kind' => (string) $kind,
		);
		foreach (array('source_label' => $sourceLabel, 'currency' => $currency, 'opening_balance' => $opening, 'closing_balance' => $closing) as $field => $value) {
			if ($value !== null && $value !== '') {
				$body[$field] = $value;
			}
		}
		if (($periodFrom !== null && $periodFrom !== '') || ($periodTo !== null && $periodTo !== '')) {
			$body['period'] = array('from' => (string) $periodFrom, 'to' => (string) $periodTo);
		}
		if ($acceptProbable) {
			$body['accept_probable_duplicates'] = true;
		}
		$body['lines'] = array_values($lines);

		return $body;
	}

	/**
	 * Call a route and turn the answer into a tool result or a tool error.
	 *
	 * @param string $route  API route
	 * @param array  $body   Request body
	 * @param bool   $import Import (true) or review (false)
	 * @return string
	 */
	private function run($route, array $body, $import)
	{
		$answer = $this->post($route, $body);
		$decoded = json_decode($answer['content'], true);

		if ($answer['error'] !== '' || $answer['http_code'] === 0) {
			throw new ToolCallException(self::encode(array(
				'error' => 'EMPAYMENT_UNREACHABLE',
				'detail' => $answer['error'] !== '' ? $answer['error'] : 'no answer',
				'what_to_do' => $import
					? 'The import may or may not have been written. Do NOT repeat the import: run empayment_review_statement again; a statement already imported answers already_imported.'
					: 'Try the review again later, or tell the user EMPayment could not be reached.',
			)));
		}

		if ($answer['http_code'] >= 200 && $answer['http_code'] < 300 && is_array($decoded)) {
			return self::encode(array('http_status' => $answer['http_code']) + $decoded + array('next_step' => $this->nextStep($decoded, $import)));
		}

		$result = array(
			'http_status' => $answer['http_code'],
			'error' => is_array($decoded) && isset($decoded['error']) ? $decoded['error'] : substr((string) $answer['content'], 0, 500),
		);
		$hints = is_array($decoded) ? self::hints($decoded) : array();
		if (!empty($hints)) {
			$result['server_hint'] = implode(' ', $hints);
		}
		$result['what_to_do'] = self::whatToDo($answer['http_code'], is_array($decoded) ? $decoded : array(), $import, $hints);

		throw new ToolCallException(self::encode($result));
	}

	/**
	 * Guidance after a successful answer.
	 *
	 * @param array $decoded Answer of EMPayment
	 * @param bool  $import  Import (true) or review (false)
	 * @return string
	 */
	private function nextStep(array $decoded, $import)
	{
		$status = isset($decoded['status']) ? (string) $decoded['status'] : '';
		if ($status === 'already_imported') {
			return 'Nothing to import: every line of this statement already exists in EMPayment (read identically). Tell the user; do not change any line to force an import.';
		}
		if (!$import) {
			$fp = isset($decoded['intent_fingerprint']) ? (string) $decoded['intent_fingerprint'] : '';
			$text = 'Show the user this review: counts (new, duplicates, probable_duplicates), balance_check, dolibarr_balance and warnings.';
			if (isset($decoded['dolibarr_balance']['status']) && $decoded['dolibarr_balance']['status'] === 'unavailable') {
				$text .= ' dolibarr_balance is "unavailable": Dolibarr could not compute it; do not conclude that a statement is missing.';
			}
			if (!empty($decoded['requires_probable_duplicates_confirmation'])) {
				$text .= ' Some new lines are PROBABLE DUPLICATES of existing movements (lines[].probable_duplicate_of): they are almost always the same movements read differently. Show each one with the existing movement. If the user agrees to import nothing more, stop. Only if they explicitly confirm, line by line, that these are distinct movements: run empayment_review_statement again with accept_probable_duplicates=true and import with THAT review\'s fingerprint.';
				return $text.' Do not call empayment_import_statement with this fingerprint: it would be refused (409 PROBABLE_DUPLICATES_UNCONFIRMED).';
			}

			return $text.' Only after their explicit approval, call empayment_import_statement with exactly the same arguments and intent_fingerprint="'.$fp.'".';
		}

		$screen = isset($decoded['screen_url']) ? $this->absoluteUrl((string) $decoded['screen_url']) : '';
		$text = 'Imported. Tell the user how many movements were created'.($screen !== '' ? ' and give them this link to review them: '.$screen : '').'. They reconcile them in EMPayment (CODA, Classer / rapprocher); nothing was matched or recorded.';

		$imported = isset($decoded['counts']['imported']) ? (int) $decoded['counts']['imported'] : null;
		$bankLines = isset($decoded['bank_lines']) ? (int) $decoded['bank_lines'] : null;
		$missingWarning = false;
		foreach ((array) ($decoded['warnings'] ?? array()) as $w) {
			if (is_array($w) && ($w['code'] ?? '') === 'BANK_LINES_MISSING') {
				$missingWarning = true;
			}
		}
		if ($missingWarning || ($imported !== null && $bankLines !== null && $bankLines < $imported)) {
			$text .= ' WARNING: '.($imported !== null && $bankLines !== null ? ($imported - $bankLines).' of the '.$imported.' imported movements have' : 'some imported movements have').' NO bank line in Dolibarr (bank_lines < counts.imported). Tell the user clearly: the movements exist in EMPayment but the bank account in Dolibarr is incomplete until an administrator checks them (EMPayment CODA screens). Do not import again.';
		}

		return $text;
	}

	/**
	 * Hints the server attached to a refusal (error.hint, errors[].hint).
	 *
	 * @param array $decoded Error body
	 * @return array<int, string>
	 */
	private static function hints(array $decoded)
	{
		$hints = array();
		if (isset($decoded['error']['hint']) && is_string($decoded['error']['hint'])) {
			$hints[] = $decoded['error']['hint'];
		}
		if (isset($decoded['hint']) && is_string($decoded['hint'])) {
			$hints[] = $decoded['hint'];
		}
		foreach ((array) ($decoded['error']['errors'] ?? array()) as $e) {
			if (is_array($e) && isset($e['hint']) && is_string($e['hint'])) {
				$hints[] = $e['hint'];
			}
		}

		return array_values(array_unique($hints));
	}

	/**
	 * Guidance after a refusal: one sentence per error code found, then the
	 * general rule of the HTTP status.
	 *
	 * @param int                $httpCode HTTP status
	 * @param array              $decoded  Error body
	 * @param bool               $import   Import (true) or review (false)
	 * @param array<int, string> $hints    Server hints
	 * @return string
	 */
	private static function whatToDo($httpCode, array $decoded, $import, array $hints = array())
	{
		$codes = array();
		foreach ((array) ($decoded['error']['errors'] ?? array()) as $e) {
			if (is_array($e) && isset($e['code'])) {
				$codes[(string) $e['code']] = true;
			}
		}

		$byCode = array(
			'LINE_EXTERNAL_ID_CONFLICT' => 'LINE_EXTERNAL_ID_CONFLICT: an existing movement has the same external_id but another date or amount, so that external_id is not the issuer\'s unique transaction id (probably a line number, a counter or an authorization code). Remove external_id from the lines concerned (from ALL lines if it is a counter of the statement) and run empayment_review_statement again. Never change the date or the amount.',
			'PROBABLE_DUPLICATES_UNCONFIRMED' => 'PROBABLE_DUPLICATES_UNCONFIRMED: some new lines look like movements already imported (listed in the error). Show each one to the user with the existing movement; they are almost always the same movement read differently. Only if the user explicitly confirms, line by line, that they are distinct: run empayment_review_statement again with accept_probable_duplicates=true and import with that new fingerprint. Otherwise import nothing more.',
			'LINE_FIELD_UNKNOWN' => 'LINE_FIELD_UNKNOWN: a line carries a key outside date, value_date, amount, counterparty, communication, external_id, original_amount (error field = the key). Remove it; express a debit by a negative amount, never by an extra key. Then run the review again.',
			'FIELD_UNKNOWN' => 'FIELD_UNKNOWN: the request carries a field outside the contract; send only the tool\'s parameters, then run the review again.',
			'BALANCE_MISMATCH' => 'BALANCE_MISMATCH: the lines do not add up to the difference between the balances. Balances are signed from the account\'s point of view: if the statement prints positive balances for a card (amounts owed), try the opposite of BOTH balances first. Never flip the sign of the lines to pass the check. Otherwise a line was misread, missed or signed wrong: re-read the statement; tell the user if you cannot find the gap.',
			'CURRENCY_MISMATCH' => 'CURRENCY_MISMATCH: the statement currency is not the currency of this account. Ask the user which account to use.',
			'ACCOUNT_CLOSED' => 'ACCOUNT_CLOSED: this bank account is closed. Ask the user which account to use.',
			'STALE_INTENT' => 'STALE_INTENT: the situation changed since the review. Run empayment_review_statement again, show it again, and import only with the new fingerprint.',
			'CONCURRENT_IMPORT' => 'CONCURRENT_IMPORT: another import was running. Run empayment_review_statement again, show it again, and import only with the new fingerprint.',
		);
		$parts = array();
		foreach ($byCode as $code => $text) {
			if (isset($codes[$code])) {
				$parts[] = $text;
			}
		}
		if (!empty($hints) && isset($codes['BALANCE_MISMATCH'])) {
			$parts[] = 'Server hint: '.implode(' ', $hints);
		}

		switch ((int) $httpCode) {
			case 400:
				array_unshift($parts, 'The statement was refused as a whole, nothing was written. error.errors lists EVERY faulty line (index = position in lines). Re-read those lines on the statement and fix the reading (dates YYYY-MM-DD, amounts as signed strings with a dot, a counterparty or communication on each line, unique external_id), then run empayment_review_statement again.');
				break;
			case 401:
				$parts[] = 'Authentication refused: the user must reconnect the AI client.';
				break;
			case 403:
				$parts[] = $import
					? 'The user lacks a right to import: EMPayment "Import CODA files" (coda_setup) and Banks "Create/modify" are both needed. Ask an administrator.'
					: 'The user lacks a right to review: EMPayment "View CODA transactions" (coda_read) and Banks "Read" are both needed. Ask an administrator.';
				break;
			case 404:
				$parts[] = 'No bank account with this id in this Dolibarr (and entity). Ask the user which account to use (dolibarr_list resource "bankaccounts"); never guess it.';
				break;
			case 409:
				if (empty($parts)) {
					$parts[] = 'The situation changed since the review. Run empayment_review_statement again, show it again to the user, and import only with the new fingerprint.';
				}
				break;
			case 422:
				if (empty($parts)) {
					$parts[] = 'The statement cannot be imported on this account; tell the user the reason given in error.';
				}
				array_unshift($parts, 'Refused, nothing was written.');
				break;
			case 503:
				$parts[] = 'EMPayment could not complete the operation. Do NOT repeat the import call: run empayment_review_statement again; a statement already imported answers already_imported.';
				break;
			default:
				if (empty($parts)) {
					$parts[] = 'Unexpected answer from EMPayment: tell the user the error.';
				}
		}

		return implode(' ', $parts);
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
