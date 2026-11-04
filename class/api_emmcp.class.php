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
 * \file    class/api_emmcp.class.php
 * \ingroup emmcp
 * \brief   REST endpoints emMCP needs and the core API does not offer.
 *
 * The core endpoints POST tasks/{id}/addtimespent and PUT tasks/{id}/timespent/{tid}
 * (Dolibarr 22.0.3) always store element_date_withhour = 1, read the date as GMT and
 * drop any product: a date-only entry shows up at 02:00 and fk_product stays empty.
 * The Task class itself supports all three, so these endpoints only pass them
 * through. Nothing in the core is patched.
 */

use Luracast\Restler\RestException;

require_once DOL_DOCUMENT_ROOT.'/projet/class/task.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/timespent.class.php';

/**
 * emMCP REST API
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class Emmcp extends DolibarrApi
{
	/**
	 * Constructor
	 */
	public function __construct()
	{
		global $db;
		$this->db = $db;
	}

	/**
	 * Create a time spent line on a task, with or without a start hour and with a product.
	 *
	 * Body: { "task_id": 12, "date": "2026-11-04" or "2026-11-04 09:30:00", "duration": 2700,
	 *         "with_hour": false, "user_id": 0, "note": "...", "product_id": 1, "progress": 50 }
	 * A date without a time creates a date-only line (stored at 12:00 like the
	 * Dolibarr timesheet does, with element_date_withhour = 0). Times are local
	 * time of the Dolibarr server.
	 *
	 * @param array $request_data Request data
	 * @return array Created line
	 *
	 * @url POST timespent
	 *
	 * @throws RestException 400, 403, 404, 500
	 */
	public function postTimeSpent($request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('projet', 'creer')) {
			throw new RestException(403, 'Missing permission to create time spent (projet/creer)');
		}
		$data = is_array($request_data) ? $request_data : array();

		$taskId = isset($data['task_id']) ? (int) $data['task_id'] : 0;
		$task = new Task($this->db);
		if ($taskId <= 0 || $task->fetch($taskId) <= 0) {
			throw new RestException(404, 'Task not found');
		}
		if (!DolibarrApi::_checkAccessToResource('project', $task->fk_project)) {
			throw new RestException(403, 'Access not allowed for login '.DolibarrApiAccess::$user->login);
		}

		$parsed = self::parseDate(isset($data['date']) ? $data['date'] : '', array_key_exists('with_hour', $data) ? $data['with_hour'] : null);
		$duration = isset($data['duration']) ? (int) $data['duration'] : 0;
		if ($duration <= 0) {
			throw new RestException(400, 'duration must be a positive number of seconds');
		}
		$productId = $this->checkProduct(isset($data['product_id']) ? $data['product_id'] : null);

		$task->timespent_date = $parsed['timestamp'];
		$task->timespent_datehour = $parsed['timestamp'];
		$task->timespent_withhour = $parsed['with_hour'];
		$task->timespent_duration = $duration;
		$task->timespent_fk_user = !empty($data['user_id']) ? (int) $data['user_id'] : (int) DolibarrApiAccess::$user->id;
		$task->timespent_note = isset($data['note']) ? (string) $data['note'] : '';
		$task->timespent_fk_product = $productId;
		if (isset($data['progress']) && $data['progress'] !== '' && $data['progress'] !== null) {
			$progress = (int) $data['progress'];
			if ($progress < 0 || $progress > 100) {
				throw new RestException(400, 'progress must be between 0 and 100');
			}
			$task->progress = $progress;
		}

		$result = $task->addTimeSpent(DolibarrApiAccess::$user);
		if ($result <= 0) {
			throw new RestException(500, 'Error when adding time spent: '.$task->error);
		}

		return $this->lineToArray((int) $task->timespent_id);
	}

	/**
	 * Get one time spent line.
	 *
	 * @param int $id Time spent ID (llx_element_time.rowid)
	 * @return array Line
	 *
	 * @url GET timespent/{id}
	 *
	 * @throws RestException 403, 404
	 */
	public function getTimeSpent($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('projet', 'lire')) {
			throw new RestException(403, 'Missing permission to read projects (projet/lire)');
		}
		$task = $this->fetchLineTask((int) $id);
		if (!DolibarrApi::_checkAccessToResource('project', $task->fk_project)) {
			throw new RestException(403, 'Access not allowed for login '.DolibarrApiAccess::$user->login);
		}

		return $this->lineToArray((int) $id);
	}

	/**
	 * Update an existing time spent line. Only the fields sent are changed.
	 *
	 * Body (all optional): { "date": "2026-11-04" or "2026-11-04 09:30:00", "with_hour": false,
	 *                        "duration": 1800, "note": "...", "product_id": 1, "user_id": 3 }
	 * "product_id": 0 removes the product. A line already invoiced is refused (409):
	 * changing it would desynchronise the invoice.
	 *
	 * @param int   $id           Time spent ID (llx_element_time.rowid)
	 * @param array $request_data Request data
	 * @return array Updated line
	 *
	 * @url PUT timespent/{id}
	 *
	 * @throws RestException 400, 403, 404, 409, 500
	 */
	public function putTimeSpent($id, $request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('projet', 'creer')) {
			throw new RestException(403, 'Missing permission to modify time spent (projet/creer)');
		}
		$data = is_array($request_data) ? $request_data : array();
		$id = (int) $id;

		$task = $this->fetchLineTask($id);
		if (!DolibarrApi::_checkAccessToResource('project', $task->fk_project)) {
			throw new RestException(403, 'Access not allowed for login '.DolibarrApiAccess::$user->login);
		}

		$line = new TimeSpent($this->db);
		$line->fetch($id);
		if (!empty($line->invoice_id)) {
			throw new RestException(409, 'Time spent line '.$id.' is already invoiced (invoice id '.((int) $line->invoice_id).'); it cannot be modified');
		}

		$changed = array();
		if (array_key_exists('date', $data) || array_key_exists('with_hour', $data)) {
			if (!array_key_exists('date', $data)) {
				// Only the hour mode changes: keep the stored day (and hour when switching on).
				$current = dol_print_date($task->timespent_date, $data['with_hour'] ? '%Y-%m-%d %H:%M:%S' : '%Y-%m-%d', 'tzserver');
				$parsed = self::parseDate($current, $data['with_hour']);
			} else {
				$parsed = self::parseDate($data['date'], array_key_exists('with_hour', $data) ? $data['with_hour'] : null);
			}
			$task->timespent_date = $parsed['timestamp'];
			$task->timespent_datehour = $parsed['timestamp'];
			$task->timespent_withhour = $parsed['with_hour'];
			$changed[] = 'date';
		}
		$task->timespent_old_duration = $task->timespent_duration;
		if (array_key_exists('duration', $data)) {
			$duration = (int) $data['duration'];
			if ($duration <= 0) {
				throw new RestException(400, 'duration must be a positive number of seconds');
			}
			$task->timespent_duration = $duration;
			$changed[] = 'duration';
		}
		if (array_key_exists('note', $data)) {
			$task->timespent_note = (string) $data['note'];
			$changed[] = 'note';
		}
		if (array_key_exists('product_id', $data)) {
			$task->timespent_fk_product = $this->checkProduct($data['product_id']);
			$changed[] = 'product';
		}
		if (array_key_exists('user_id', $data) && (int) $data['user_id'] > 0) {
			$task->timespent_fk_user = (int) $data['user_id'];
			$changed[] = 'user';
		}
		if (empty($changed)) {
			throw new RestException(400, 'Nothing to update: send at least one of date, with_hour, duration, note, product_id, user_id');
		}

		// Task::fetchTimeSpent() does not load the invoice link, while updateTimeSpent()
		// writes it back: carry it over explicitly so it can never be cleared here.
		$task->timespent_invoiceid = $line->invoice_id;
		$task->timespent_invoicelineid = $line->invoice_line_id;

		$result = $task->updateTimeSpent(DolibarrApiAccess::$user);
		if ($result <= 0) {
			throw new RestException(500, 'Error when updating time spent: '.$task->error);
		}

		return $this->lineToArray($id);
	}

	/**
	 * Parse "YYYY-MM-DD" or "YYYY-MM-DD HH:MM[:SS]" (server local time).
	 *
	 * @param  mixed     $date     Date string
	 * @param  bool|null $withHour Forced hour mode, or null to infer it from the string
	 * @return array{timestamp:int, with_hour:int}
	 *
	 * @throws RestException 400
	 */
	public static function parseDate($date, $withHour = null)
	{
		$date = trim((string) $date);
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/', $date, $m)) {
			throw new RestException(400, 'date must be YYYY-MM-DD (date only) or YYYY-MM-DD HH:MM[:SS] (with hour)');
		}
		$hasTime = isset($m[4]) && $m[4] !== '';
		if ($withHour === null || $withHour === '') {
			$withHour = $hasTime;
		} else {
			$withHour = filter_var($withHour, FILTER_VALIDATE_BOOLEAN);
		}
		if ($withHour && !$hasTime) {
			throw new RestException(400, 'with_hour is true but the date has no time; send YYYY-MM-DD HH:MM:SS');
		}
		if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
			throw new RestException(400, 'date is not a valid calendar date');
		}
		if ($withHour) {
			$ts = dol_mktime((int) $m[4], (int) $m[5], isset($m[6]) && $m[6] !== '' ? (int) $m[6] : 0, (int) $m[2], (int) $m[3], (int) $m[1], 'tzserver');
		} else {
			// Same convention as projet/tasks/time.php for a line without hour.
			$ts = dol_mktime(12, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1], 'tzserver');
		}

		return array('timestamp' => (int) $ts, 'with_hour' => $withHour ? 1 : 0);
	}

	/**
	 * Validate a product id; 0, '' and null mean "no product".
	 *
	 * @param  mixed $productId Product id
	 * @return int|null
	 *
	 * @throws RestException 400
	 */
	private function checkProduct($productId)
	{
		if ($productId === null || $productId === '' || (int) $productId === 0) {
			return null;
		}
		$productId = (int) $productId;
		require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
		$product = new Product($this->db);
		if ($productId < 0 || $product->fetch($productId) <= 0) {
			throw new RestException(400, 'product_id '.$productId.' does not exist');
		}
		return $productId;
	}

	/**
	 * Load the task a time spent line belongs to (the line fields included).
	 *
	 * @param  int  $id Time spent ID
	 * @return Task
	 *
	 * @throws RestException 404
	 */
	private function fetchLineTask($id)
	{
		$task = new Task($this->db);
		if ($id <= 0 || $task->fetchTimeSpent($id) <= 0) {
			throw new RestException(404, 'Time spent line not found');
		}
		// fetchTimeSpent() only sets the line fields and $task->id: load the task itself
		// (fk_project is needed for the access check) without losing them.
		$line = array(
			'timespent_id', 'timespent_date', 'timespent_datehour', 'timespent_withhour', 'timespent_duration',
			'timespent_fk_user', 'timespent_fk_product', 'timespent_thm', 'timespent_note',
		);
		$keep = array();
		foreach ($line as $field) {
			$keep[$field] = $task->$field;
		}
		if ($task->fetch($task->id) <= 0) {
			throw new RestException(404, 'Task of the time spent line not found');
		}
		foreach ($keep as $field => $value) {
			$task->$field = $value;
		}
		return $task;
	}

	/**
	 * Read back a line as stored.
	 *
	 * @param  int   $id Time spent ID
	 * @return array
	 */
	private function lineToArray($id)
	{
		$sql = "SELECT rowid, fk_element, element_date, element_datehour, element_date_withhour, element_duration,";
		$sql .= " fk_user, fk_product, note, invoice_id, invoice_line_id, thm";
		$sql .= " FROM ".MAIN_DB_PREFIX."element_time WHERE rowid = ".((int) $id)." AND elementtype = 'task'";
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if (!$obj) {
			throw new RestException(404, 'Time spent line not found');
		}

		return array(
			'id' => (int) $obj->rowid,
			'task_id' => (int) $obj->fk_element,
			'date' => (string) $obj->element_date,
			'datehour' => (string) $obj->element_datehour,
			'with_hour' => (int) $obj->element_date_withhour,
			'duration' => (int) $obj->element_duration,
			'user_id' => (int) $obj->fk_user,
			'product_id' => $obj->fk_product !== null && (int) $obj->fk_product > 0 ? (int) $obj->fk_product : null,
			'note' => (string) $obj->note,
			'invoice_id' => $obj->invoice_id !== null && (int) $obj->invoice_id > 0 ? (int) $obj->invoice_id : null,
		);
	}
}
