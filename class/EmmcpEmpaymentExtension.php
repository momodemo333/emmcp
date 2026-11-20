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
 * \file    class/EmmcpEmpaymentExtension.php
 * \ingroup emmcp
 * \brief   Tools emMCP itself provides for the EMPayment module (statement import).
 *
 * Same extension point as the tools other modules hand over through the
 * emmcpRegisterTools hook; registered by emmcp_mcp_extensions() only when
 * EMPayment 2.7.0+ is enabled in the entity of the request and the user may
 * review a statement (EmmcpEmpaymentTools::isAvailable()).
 */

dol_include_once('/emmcp/class/EmmcpEmpaymentTools.php');

class EmmcpEmpaymentExtension implements \DolibarrMcp\Extension\ToolExtension
{
	/** @var EmmcpEmpaymentTools */
	private $tools;

	/**
	 * @param EmmcpEmpaymentTools $tools Tool provider
	 */
	public function __construct($tools)
	{
		$this->tools = $tools;
	}

	public function name(): string
	{
		return 'emmcp-empayment';
	}

	public function toolProviders(): iterable
	{
		return array($this->tools);
	}
}
