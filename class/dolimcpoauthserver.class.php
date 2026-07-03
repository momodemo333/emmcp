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
 * \file    class/dolimcpoauthserver.class.php
 * \ingroup dolimcp
 * \brief   Minimal OAuth 2.1 authorization server for the MCP endpoint.
 *
 * Implements the subset required by the MCP authorization spec:
 * authorization code + PKCE (S256 only), refresh token rotation,
 * RFC 7591 dynamic client registration. Tokens are opaque random
 * strings; only their sha256 hash is stored.
 */

class DoliMcpOAuthServer
{
	const ACCESS_TOKEN_TTL = 3600;          // 1 hour
	const REFRESH_TOKEN_TTL = 2592000;      // 30 days
	const AUTH_CODE_TTL = 120;              // 2 minutes
	const TOKEN_PREFIX = 'dmcp_';

	/** @var DoliDB */
	public $db;

	/** @var string Last error message */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	// --- Clients (RFC 7591) -------------------------------------------------

	/**
	 * Register a new OAuth client (Dynamic Client Registration).
	 *
	 * @param array $metadata Parsed JSON registration request
	 * @return array|null Registration response (RFC 7591), or null on error ($this->error set)
	 */
	public function registerClient(array $metadata)
	{
		global $conf;

		$redirectUris = $metadata['redirect_uris'] ?? array();
		if (!is_array($redirectUris) || empty($redirectUris)) {
			$this->error = 'redirect_uris is required';
			return null;
		}
		foreach ($redirectUris as $uri) {
			if (!is_string($uri) || !$this->isAcceptableRedirectUri($uri)) {
				$this->error = 'Invalid redirect_uri: must be https or a localhost loopback URL';
				return null;
			}
		}

		$authMethod = $metadata['token_endpoint_auth_method'] ?? 'none';
		if (!in_array($authMethod, array('none', 'client_secret_post', 'client_secret_basic'), true)) {
			$this->error = 'Unsupported token_endpoint_auth_method';
			return null;
		}

		$clientId = self::TOKEN_PREFIX.'client_'.bin2hex(random_bytes(16));
		$clientSecret = null;
		$secretHash = null;
		if ($authMethod !== 'none') {
			$clientSecret = bin2hex(random_bytes(32));
			$secretHash = password_hash($clientSecret, PASSWORD_DEFAULT);
		}

		$clientName = dol_trunc((string) ($metadata['client_name'] ?? ''), 250);

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."dolimcp_oauth_client";
		$sql .= " (client_id, client_secret_hash, client_name, redirect_uris, token_endpoint_auth_method, entity, datec)";
		$sql .= " VALUES ('".$this->db->escape($clientId)."',";
		$sql .= " ".($secretHash ? "'".$this->db->escape($secretHash)."'" : "NULL").",";
		$sql .= " '".$this->db->escape($clientName)."',";
		$sql .= " '".$this->db->escape(json_encode(array_values($redirectUris)))."',";
		$sql .= " '".$this->db->escape($authMethod)."',";
		$sql .= " ".((int) $conf->entity).",";
		$sql .= " '".$this->db->idate(dol_now())."')";

		if (!$this->db->query($sql)) {
			$this->error = 'DB error: '.$this->db->lasterror();
			return null;
		}

		dol_syslog('[DOLIMCP] OAuth client registered: '.$clientId.' ('.$clientName.')', LOG_INFO);

		$response = array(
			'client_id' => $clientId,
			'client_id_issued_at' => dol_now(),
			'client_name' => $clientName,
			'redirect_uris' => array_values($redirectUris),
			'grant_types' => array('authorization_code', 'refresh_token'),
			'response_types' => array('code'),
			'token_endpoint_auth_method' => $authMethod,
		);
		if ($clientSecret !== null) {
			$response['client_secret'] = $clientSecret; // returned once, only the hash is stored
		}

		return $response;
	}

	/**
	 * Fetch a registered client by its client_id.
	 *
	 * @param string $clientId Client identifier
	 * @return object|null Row object or null
	 */
	public function getClient($clientId)
	{
		$sql = "SELECT rowid, client_id, client_secret_hash, client_name, redirect_uris, token_endpoint_auth_method";
		$sql .= " FROM ".MAIN_DB_PREFIX."dolimcp_oauth_client";
		$sql .= " WHERE client_id = '".$this->db->escape($clientId)."'";

		$resql = $this->db->query($sql);
		if ($resql && $this->db->num_rows($resql) === 1) {
			return $this->db->fetch_object($resql);
		}
		return null;
	}

	/**
	 * Authenticate a client on the token endpoint according to its
	 * registered token_endpoint_auth_method.
	 *
	 * @param object      $client Client row
	 * @param string|null $secret Secret provided in the request (body or Basic auth), if any
	 * @return bool
	 */
	public function authenticateClient($client, $secret)
	{
		if ($client->token_endpoint_auth_method === 'none') {
			return true; // public client, PKCE is the protection
		}
		return !empty($secret) && !empty($client->client_secret_hash)
			&& password_verify($secret, $client->client_secret_hash);
	}

	/**
	 * Validate a redirect_uri against the client's registered URIs.
	 * Exact match, except for loopback hosts where the port is ignored
	 * (RFC 8252 §7.3 — native clients bind a random localhost port).
	 *
	 * @param object $client Client row
	 * @param string $uri    redirect_uri from the request
	 * @return bool
	 */
	public function isRegisteredRedirectUri($client, $uri)
	{
		$registered = json_decode((string) $client->redirect_uris, true);
		if (!is_array($registered)) {
			return false;
		}

		if (in_array($uri, $registered, true)) {
			return true;
		}

		$p = parse_url($uri);
		if (!$p || !in_array($p['host'] ?? '', array('127.0.0.1', 'localhost', '[::1]'), true)) {
			return false;
		}
		foreach ($registered as $reg) {
			$r = parse_url($reg);
			if ($r && in_array($r['host'] ?? '', array('127.0.0.1', 'localhost', '[::1]'), true)
				&& ($r['scheme'] ?? '') === ($p['scheme'] ?? '')
				&& ($r['path'] ?? '/') === ($p['path'] ?? '/')) {
				return true; // same loopback URI, any port
			}
		}
		return false;
	}

	/**
	 * A redirect URI is acceptable if https, or http on a loopback host.
	 *
	 * @param string $uri Redirect URI
	 * @return bool
	 */
	private function isAcceptableRedirectUri($uri)
	{
		$p = parse_url($uri);
		if (!$p || empty($p['scheme']) || empty($p['host'])) {
			return false;
		}
		if ($p['scheme'] === 'https') {
			return true;
		}
		return $p['scheme'] === 'http' && in_array($p['host'], array('127.0.0.1', 'localhost', '[::1]'), true);
	}

	// --- Grants ---------------------------------------------------------------

	/**
	 * Issue an authorization code after user consent.
	 *
	 * @param object $client        Client row
	 * @param int    $userId        Dolibarr user granting access
	 * @param string $redirectUri   Redirect URI of the request (re-checked at exchange)
	 * @param string $codeChallenge PKCE S256 challenge
	 * @param string $scope         Requested scope
	 * @param string $resource      RFC 8707 resource indicator
	 * @return string|null The code, or null on error
	 */
	public function createAuthorizationCode($client, $userId, $redirectUri, $codeChallenge, $scope, $resource)
	{
		$code = self::TOKEN_PREFIX.'c'.bin2hex(random_bytes(32));

		if (!$this->insertToken('code', $code, (int) $client->rowid, $userId, $scope, $resource, $codeChallenge, $redirectUri, self::AUTH_CODE_TTL)) {
			return null;
		}

		return $code;
	}

	/**
	 * Exchange an authorization code for tokens (with PKCE verification).
	 *
	 * @param object $client       Authenticated client row
	 * @param string $code         Authorization code
	 * @param string $redirectUri  redirect_uri from the token request
	 * @param string $codeVerifier PKCE verifier
	 * @return array|null Token response, or null ($this->error set to an OAuth error code)
	 */
	public function exchangeAuthorizationCode($client, $code, $redirectUri, $codeVerifier)
	{
		$row = $this->getValidToken('code', $code);
		if (!$row || (int) $row->fk_client !== (int) $client->rowid) {
			$this->error = 'invalid_grant';
			return null;
		}

		// One-shot: revoke immediately, whatever happens next
		$this->revokeToken($row->rowid);

		if (!empty($row->redirect_uri) && $row->redirect_uri !== $redirectUri) {
			$this->error = 'invalid_grant';
			return null;
		}

		// PKCE S256 (mandatory, only supported method)
		if (empty($row->code_challenge) || empty($codeVerifier)) {
			$this->error = 'invalid_grant';
			return null;
		}
		$computed = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
		if (!hash_equals($row->code_challenge, $computed)) {
			$this->error = 'invalid_grant';
			return null;
		}

		return $this->issueTokenPair((int) $client->rowid, (int) $row->fk_user, (string) $row->scope, (string) $row->resource);
	}

	/**
	 * Rotate a refresh token: revoke it and issue a new access+refresh pair.
	 *
	 * @param object $client       Authenticated client row
	 * @param string $refreshToken Current refresh token
	 * @return array|null Token response, or null ($this->error set)
	 */
	public function refreshTokens($client, $refreshToken)
	{
		$row = $this->getValidToken('refresh', $refreshToken);
		if (!$row || (int) $row->fk_client !== (int) $client->rowid) {
			$this->error = 'invalid_grant';
			return null;
		}

		$this->revokeToken($row->rowid);

		return $this->issueTokenPair((int) $client->rowid, (int) $row->fk_user, (string) $row->scope, (string) $row->resource);
	}

	/**
	 * Validate a Bearer access token.
	 *
	 * @param string $token Access token presented by the MCP client
	 * @return object|null Token row (with fk_user) or null
	 */
	public function validateAccessToken($token)
	{
		return $this->getValidToken('access', $token);
	}

	/**
	 * Resolve the Dolibarr REST API key of a user (generating one if absent),
	 * so the MCP tools can act through the REST API on the user's behalf.
	 *
	 * @param int $userId User rowid
	 * @return string|null Clear-text API key, or null ($this->error set)
	 */
	public function getUserApiKey($userId)
	{
		$sql = "SELECT api_key FROM ".MAIN_DB_PREFIX."user";
		$sql .= " WHERE rowid = ".((int) $userId)." AND statut = 1";

		$resql = $this->db->query($sql);
		if (!$resql || $this->db->num_rows($resql) !== 1) {
			$this->error = 'User not found or disabled';
			return null;
		}
		$obj = $this->db->fetch_object($resql);

		if (!empty($obj->api_key)) {
			$key = function_exists('dolDecrypt') ? dolDecrypt($obj->api_key) : $obj->api_key;
			if (!empty($key) && !preg_match('/^dolcrypt:/i', $key)) {
				return $key;
			}
		}

		// No usable key: generate one (same storage convention as the user card)
		$key = bin2hex(random_bytes(20));
		$stored = function_exists('dolEncrypt') ? dolEncrypt($key, '', '', 'dolibarr') : $key;
		$sql = "UPDATE ".MAIN_DB_PREFIX."user SET api_key = '".$this->db->escape($stored)."'";
		$sql .= " WHERE rowid = ".((int) $userId);
		if (!$this->db->query($sql)) {
			$this->error = 'Failed to generate API key: '.$this->db->lasterror();
			return null;
		}
		dol_syslog('[DOLIMCP] Generated REST API key for user '.$userId.' (OAuth consent)', LOG_INFO);

		return $key;
	}

	/**
	 * Opportunistic cleanup of expired/revoked grants (called from the token endpoint).
	 *
	 * @return void
	 */
	public function purgeExpired()
	{
		$sql = "DELETE FROM ".MAIN_DB_PREFIX."dolimcp_oauth_token";
		$sql .= " WHERE expires_at < '".$this->db->idate(dol_now() - 86400)."'";
		$this->db->query($sql);
	}

	// --- Internals --------------------------------------------------------------

	/**
	 * Issue an access token + refresh token pair.
	 *
	 * @param int    $clientRowid Client rowid
	 * @param int    $userId      User rowid
	 * @param string $scope       Scope
	 * @param string $resource    Resource indicator
	 * @return array|null OAuth token response
	 */
	private function issueTokenPair($clientRowid, $userId, $scope, $resource)
	{
		$access = self::TOKEN_PREFIX.'a'.bin2hex(random_bytes(32));
		$refresh = self::TOKEN_PREFIX.'r'.bin2hex(random_bytes(32));

		if (!$this->insertToken('access', $access, $clientRowid, $userId, $scope, $resource, null, null, self::ACCESS_TOKEN_TTL)
			|| !$this->insertToken('refresh', $refresh, $clientRowid, $userId, $scope, $resource, null, null, self::REFRESH_TOKEN_TTL)) {
			$this->error = 'server_error';
			return null;
		}

		$response = array(
			'access_token' => $access,
			'token_type' => 'Bearer',
			'expires_in' => self::ACCESS_TOKEN_TTL,
			'refresh_token' => $refresh,
		);
		if (!empty($scope)) {
			$response['scope'] = $scope;
		}

		return $response;
	}

	/**
	 * Insert a token row (only the sha256 hash of the token is stored).
	 *
	 * @param string      $type          'code' | 'access' | 'refresh'
	 * @param string      $token         Clear token value
	 * @param int         $clientRowid   Client rowid
	 * @param int         $userId        User rowid
	 * @param string      $scope         Scope
	 * @param string      $resource      Resource indicator
	 * @param string|null $codeChallenge PKCE challenge (codes only)
	 * @param string|null $redirectUri   Redirect URI (codes only)
	 * @param int         $ttl           Lifetime in seconds
	 * @return bool
	 */
	private function insertToken($type, $token, $clientRowid, $userId, $scope, $resource, $codeChallenge, $redirectUri, $ttl)
	{
		global $conf;

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."dolimcp_oauth_token";
		$sql .= " (token_type, token_hash, fk_client, fk_user, scope, resource, code_challenge, redirect_uri, expires_at, revoked, entity, datec)";
		$sql .= " VALUES ('".$this->db->escape($type)."',";
		$sql .= " '".$this->db->escape(hash('sha256', $token))."',";
		$sql .= " ".((int) $clientRowid).",";
		$sql .= " ".((int) $userId).",";
		$sql .= " ".($scope !== '' && $scope !== null ? "'".$this->db->escape($scope)."'" : "NULL").",";
		$sql .= " ".($resource !== '' && $resource !== null ? "'".$this->db->escape($resource)."'" : "NULL").",";
		$sql .= " ".($codeChallenge ? "'".$this->db->escape($codeChallenge)."'" : "NULL").",";
		$sql .= " ".($redirectUri ? "'".$this->db->escape($redirectUri)."'" : "NULL").",";
		$sql .= " '".$this->db->idate(dol_now() + $ttl)."',";
		$sql .= " 0,";
		$sql .= " ".((int) $conf->entity).",";
		$sql .= " '".$this->db->idate(dol_now())."')";

		if (!$this->db->query($sql)) {
			dol_syslog('[DOLIMCP] ERROR insertToken: '.$this->db->lasterror(), LOG_ERR);
			return false;
		}
		return true;
	}

	/**
	 * Fetch a non-revoked, non-expired token row by type + clear value.
	 *
	 * @param string $type  Token type
	 * @param string $token Clear token value
	 * @return object|null
	 */
	private function getValidToken($type, $token)
	{
		if (empty($token)) {
			return null;
		}

		$sql = "SELECT rowid, token_type, fk_client, fk_user, scope, resource, code_challenge, redirect_uri, expires_at, revoked";
		$sql .= " FROM ".MAIN_DB_PREFIX."dolimcp_oauth_token";
		$sql .= " WHERE token_type = '".$this->db->escape($type)."'";
		$sql .= " AND token_hash = '".$this->db->escape(hash('sha256', $token))."'";
		$sql .= " AND revoked = 0";
		$sql .= " AND expires_at > '".$this->db->idate(dol_now())."'";

		$resql = $this->db->query($sql);
		if ($resql && $this->db->num_rows($resql) === 1) {
			return $this->db->fetch_object($resql);
		}
		return null;
	}

	/**
	 * Mark a token row as revoked.
	 *
	 * @param int $rowid Token rowid
	 * @return void
	 */
	private function revokeToken($rowid)
	{
		$sql = "UPDATE ".MAIN_DB_PREFIX."dolimcp_oauth_token SET revoked = 1 WHERE rowid = ".((int) $rowid);
		$this->db->query($sql);
	}
}
