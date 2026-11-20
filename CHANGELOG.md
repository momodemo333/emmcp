# Changelog

## [1.12.0] - 2026-11-20

### Added
- **New tool `empayment_import_statement`: give the AI a card or bank
  statement (PDF, CSV, photo) and it creates the movements in EMPayment**, to
  reconcile in EMPayment's CODA screens. Offered only when EMPayment 2.7.0 or
  later is enabled in the entity of the connection and the user may review a
  statement (EMPayment "View CODA transactions" and Banks "Read"); importing
  also needs EMPayment "Import CODA files" and Banks "Create/modify".
  - Two calls, as every EMPayment action: a review that writes nothing
    (new lines, duplicates, probable duplicates, balance check, warnings,
    fingerprint), then the import with `confirm=true` and that fingerprint.
  - The tool tells the AI to show the review to the user before confirming,
    never to guess the bank account, to sign amounts from the account's point
    of view (card purchase negative), to copy the transaction number whenever
    the statement prints one, to give the opening and closing balances, and
    never to alter a line to get round a reported duplicate (sending the same
    statement again creates nothing).
  - Every refusal comes back whole, with what to do next: all faulty lines of
    a refused statement (EMPayment lists them all), balance mismatch, unknown
    account, stale review (409: review again), and never a second import call
    after a 503 or a lost answer.
  - Calls EMPayment's REST routes with the API key and entity of the MCP
    session (Multicompany included); EMPayment's own rights, entity and
    duplicate rules apply. Behind a proxy that redirects http to https, the
    call is retried once over https on the same host.

### Notes
- No database change (schema version 1.12.0, nothing to migrate). Nothing
  changes without EMPayment 2.7.0+. A French changelog now ships with the
  module (`CHANGELOG-fr_FR.md`).

## [1.11.0] - 2026-11-20

### Added
- **Multicompany: an AI client can work in any entity the user has access
  to.** Until now `mcp.php` always ran in entity 1 and the tools worked in the
  main entity of the user: a user shared between entities (transverse mode,
  created in the master entity) could not connect Claude or ChatGPT to the
  entity they actually work in, and a module enabled only in another entity
  answered "Module emMCP not enabled".
  - The OAuth consent screen shows the entity being connected; with the plain
    connector URL the user picks it among the entities they have access to and
    where emMCP is enabled. The entity is stored on the authorization code and
    inherited by the access and refresh tokens.
  - Connector URL per entity: `mcp.php?entity=N`, shown in **Settings** and
    **Diagnostic** for the current entity. It fixes the entity on the consent
    screen, and selects the entity for API key clients (default: the user's
    main entity).
  - Every request checks the user's access to the entity (rule of
    Multicompany: superadmin everywhere, otherwise a user group of the entity
    in transverse mode or the entity itself; the entity must be active), and
    that emMCP is enabled there. Refusals are explicit (403 with the reason,
    logged in the connection log of the entity concerned); a token granted for
    one entity is refused for another.
  - The REST calls of the tools carry the native `DOLAPIENTITY` header
    (dolibarr-mcp-server 2.8.0), so every read and write happens in that
    entity with the user's rights there.
  - Granted accesses, activity log and connection log are per entity, as their
    tables already were: each entity sees its own. Revoking from the
    **Granted accesses** tab only acts on an access listed in the current
    entity.
  - Diagnostic: a Multicompany line (mode, current entity, emMCP enabled
    there, entities the current user can connect, connector URL).
- Without Multicompany nothing changes: everything stays in entity 1 and no
  entity header is sent.

### Security
- Read-only SQL stays refused under Multicompany, now also checked install-wide
  by emMCP: once a request works in another entity, Multicompany's own flag may
  be absent from that entity's configuration.

### Notes
- Embeds dolibarr-mcp-server 2.8.0. No database change (schema version
  1.11.0, nothing to migrate): the token, log and access tables already had an
  entity column.

## [1.10.2] - 2026-11-17

### Fixed
- **Upgrading by unzipping over the previous version could leave PHP running
  the previous version's code.** Since 1.2.0 every file of the package carried
  the same fixed date, release after release (this made the ZIP reproducible).
  `unzip` restores that date, so the files of the new version looked unchanged
  to PHP's opcode cache, which kept serving the old code until PHP was
  restarted: a mix of old and new files, and database migrations that did not
  run. Reproduced on a test install going from 1.10.1 to 1.10.2. The package's
  files now carry the date of the release's commit, which still makes two
  builds of the same sources identical, and changes with every version.
  Installs that upgraded this way and still behave oddly are fixed by this
  upgrade, or at once by restarting PHP (PHP-FPM, or the web server).
- **Upgrading by unzipping over an older version now removes the files that
  version left behind.** Unzipping adds and replaces files but never deletes
  any, so an install that started on 1.0.0 still carried the old OAuth and SQL
  classes (`class/emmcpoauthserver.class.php`, `class/emmcpsql*.class.php`),
  an old runtime class and a few development files of dependencies, all under
  the web root. None was loaded by the module. The first request after the
  upgrade now deletes exactly those files (a fixed list, only regular files,
  only inside the module directory); a file the web server may not delete is
  logged and left in place, and never blocks the upgrade. The build refuses to
  package a file on that list.
- **Packaging safeguards.** The dependency prefixing step now names its
  exceptions exactly: php-scoper took the plain exclusion `Mcp` to mean any
  namespace segment `MCP` (seen in Dalfred on `NeuronAI\MCP\`). No dependency
  of emMCP had such a namespace, and the package content is unchanged; the
  build now also fails if any namespace of the bundled dependencies is left
  without the prefix. The build also fails when a bundled dependency directory
  is not its own git checkout: an unzipped copy used to be reported as clean.
  It also refuses to package the MCP runtime's standalone server (`public/`,
  `bin/`), which emMCP has never shipped but which answers without
  authentication. No database change (schema version 1.10.2, nothing to
  migrate).

## [1.10.1] - 2026-11-16

### Fixed
- **ChatGPT needs no web server rule: the 1.10.0 diagnosis was wrong, and
  the Diagnostic tab now checks what actually breaks ChatGPT.** A ChatGPT
  connector was tested end to end on 2026-11-16 against emMCP 1.7.0 with no
  rule at the root of the domain. The access log shows ChatGPT following the
  `WWW-Authenticate` header of the 401 to the protected resource metadata,
  trying the root of the domain for the authorization server (404), then
  falling back by itself to the module's own URLs (200), registering (201),
  getting tokens and calling tools. The 1.10.0 check and the `.htaccess` /
  nginx rules it handed out are removed. What did break discovery on a
  customer site: the hosting firewall (ModSecurity) answered 406 to the
  `aiohttp` User-Agent that ChatGPT's discovery requests carry, while
  browsers, curl and ChatGPT's later requests got through. The check button
  of the Diagnostic tab now fetches the module's discovery document with that
  User-Agent and warns when the web server refuses it (403 or 406), with what
  to ask the hosting provider. The README's ChatGPT section is rewritten from
  the observed sequence and gains a troubleshooting paragraph with a `curl`
  test. No database change (schema version 1.10.1, nothing to migrate).

## [1.10.0] - 2026-11-16

**Superseded by 1.10.1:** its premise, that ChatGPT needs the OAuth metadata
at the root of the domain, proved wrong once tested with ChatGPT itself.

### Added
- **ChatGPT: the Diagnostic tab checks OAuth discovery at the root of the
  domain and hands out the web server rules it needs.** ChatGPT's custom
  connectors stop at "Unable to detect OAuth settings" on a stock install:
  everything indicates they look for the OAuth metadata at the root of the
  domain (`/.well-known/oauth-protected-resource/custom/emmcp/mcp.php`,
  `/.well-known/oauth-authorization-server/custom/emmcp/oauth.php`, RFC 9728
  and RFC 8414 path insertion) rather than only following the
  `WWW-Authenticate` header of the 401, as claude.ai and Claude Code do. A
  module cannot answer there by itself. The check button of the Diagnostic
  tab now also queries these two URLs, verifies they name this endpoint and
  this issuer exactly, and when they do not, prints an Apache (`.htaccess`)
  rule and an nginx rule (Plesk "Additional nginx directives") already fitted
  to the instance's path. Both are internal rewrites, no redirection, and
  leave Let's Encrypt's `/.well-known/acme-challenge/` alone; both were
  proved on Apache 2.4 behind an nginx reproducing Plesk Obsidian's
  generated configuration, with the MCP SDK's reference client. The README
  has a ChatGPT section. Correction: the 1.7.0 entry below said ChatGPT was
  unaffected by the discovery change; it had never been tested with ChatGPT.
  No database change (schema version 1.10.0, nothing to migrate).

## [1.9.1] - 2026-11-13

### Changed
- **Updates are now announced to every install as soon as they are
  published.** Dolibarr's module list learns about a new emMCP version from
  e-dem.com; that version number was until now updated by hand, and could
  lag behind the release. Publishing a release now updates it automatically,
  after the package is available for download, and checks it. No change to
  the module's behaviour or database (schema version 1.9.1, nothing to
  migrate).

## [1.9.0] - 2026-11-12

### Fixed
- **HTTP 500 on every MCP call when another module, or a library of the
  Dolibarr core, loads its own copy of psr/log.** Dolibarr runs every module
  in one PHP process, and PHP cannot hold two classes of the same name: a
  psr/log 1.x `LoggerInterface` loaded first made our psr/log 3 `NullLogger`
  a fatal error ("Declaration of Psr\Log\NullLogger::log(...) must be
  compatible with Psr\Log\LoggerInterface::log(...)"). Seen on a customer
  install; the same could happen with guzzle, symfony or any PSR interface.
  The package now prefixes every third-party dependency of the embedded
  runtime under `EmmcpVendor\` at build time (php-scoper), so nothing another
  module loads can collide with it; the build proves it by loading a
  psr/log 1.x interface and a foreign guzzle class first, then serving
  `initialize` and `tools/list`. Our autoloader is also authoritative now, so
  it no longer answers for other modules' classes either.
- **An AI client left idle for about a month could no longer sign in**
  ("authorization failed" until the connector was deleted and re-created):
  its OAuth registration was purged along with its expired tokens. Only
  registrations between one and seven days old that never obtained a token
  are purged now (dolibarr-mcp-oauth 1.2.0).

### Added
- **Diagnostic tab**: the checks that can block a connection (REST API
  module, schema, MCP runtime and dependency isolation, library conflicts,
  last runtime error, HTTPS URL, OAuth accesses whose user lost their API key,
  Authorization header), the **connection log** and a plain-text report to
  send to support, without any secret.
- **Connection log** (`llx_emmcp_auth_log`, 30 days): every OAuth step
  (registration, authorization request, consent, token) and every refused MCP
  request, with the precise reason: code or refresh token unknown, expired or
  already used, redirect_uri or PKCE mismatch, unknown client_id, revoked
  access, no credentials reaching PHP... An AI client only shows a generic
  "authorization failed" and a reference its vendor alone can read; this is
  the server side of that story.
- **Runtime guard**: before the MCP runtime is used, its key types are checked
  to come from its own directory. A foreign copy (possible for the shared
  `DolibarrMcp\` and `Mcp\` namespaces, or with an unprefixed runtime) is
  answered with a JSON-RPC error naming the file that defined it, instead of a
  fatal error. A fatal error that still happens is recorded for the
  Diagnostic tab and answered in JSON.
- Token endpoint errors carry an `error_description`.

### Changed
- Embedded dolibarr-mcp-oauth 1.2.0. Database schema 1.9.0 (new table, created
  automatically on the first request after the upgrade; no need to disable and
  re-enable the module).

## [1.8.0] - 2026-11-04

### Fixed
- **Time spent lines: date-only lines and products were impossible, and
  `dolibarr_add_time_spent` claimed success anyway.** The core REST endpoints
  (Dolibarr 22.0.3) always store a start hour, read the date as GMT — a line
  meant for 00:00 showed at 01:00 or 02:00 — and drop the product. emMCP now
  adds its own REST endpoints, `POST emmcp/timespent`, `GET` and
  `PUT emmcp/timespent/{id}`, which pass the date mode and the product to the
  core Task class. Nothing in the core is patched.
  - A date `YYYY-MM-DD` creates a date-only line, stored at 12:00 like the
    Dolibarr timesheet does; `YYYY-MM-DD HH:MM:SS` is read in the server's local
    time.
  - Updating changes only the fields sent; an invoiced line is refused, and its
    invoice link is carried over explicitly (the core `updateTimeSpent()` would
    otherwise clear it, because `fetchTimeSpent()` does not load it).

### Added
- MCP tool `dolibarr_update_time_spent` (runtime 2.7.0): change the date (with
  or without hour), duration, note, product or user of an existing time spent
  line.
- **Other modules can add MCP tools.** emMCP asks the Dolibarr hook
  `emmcpRegisterTools` (context `emmcp`) for `ToolExtension` objects and hands
  them to the runtime. The hook receives `user` (the session's user, rights
  loaded) and `collector` (an `ArrayObject` to append to). A module that fails
  is logged and ignored. First user: the emMCP Extensions module (todos).

### Changed
- Embedded MCP runtime 2.7.0.

## [1.7.0] - 2026-10-06

### Security
- **Removing a user's REST API key did not end their OAuth access.** The key
  the tools act through was regenerated on every MCP call when missing, so the
  natural way for an administrator to cut someone's access was silently undone
  by the next request. The key is now created only when the user consents;
  removing it, or disabling the user, ends every OAuth access they gave, and
  the client is answered 401 so it goes back through sign-in.
- **An authorization code or refresh token could be used several times at
  once.** Twelve parallel exchanges of one code could each get tokens; now
  exactly one does. A code or refresh token presented a second time revokes
  everything issued under that grant.
- The consent form always requires its CSRF token, whatever the global setting.
- Client registration is capped at 50 per hour, and clients that never
  obtained a token are purged after a day.

### Added
- **"Granted accesses" tab**: every AI client a user connected through OAuth,
  when it was granted and last renewed, and a Revoke button that ends it at
  once. The listing now really lets an access be revoked at any time.
- **Authorization header check** on the same tab: the server calls its own
  endpoint and says whether the header reaches PHP; if not, it shows the line
  to add for Apache or nginx. Many hosts ignore the module's .htaccess, and the
  only symptom otherwise is a 401 right after a successful sign-in.
- Authorization responses carry `iss` (RFC 9207).

### Fixed
- **Claude Desktop (mcp-remote) could not connect**: it rejected the OpenID
  discovery document, which lacked `jwks_uri` (ZodError). That route now has
  its own document; claude.ai, ChatGPT and Claude Code are unaffected.
- `client_secret_basic` works under PHP-FPM.
- Creating a supplier or customer without a code now lets Dolibarr number it,
  instead of leaving it without a code or failing with a 500 (issue #2).

### Changed
- Embedded MCP runtime 2.6.1, OAuth library 1.1.0.

## [1.6.0] - 2026-09-29

### Fixed
- **Searches by field value were answered with the wrong record** (embedded MCP
  runtime 2.5.1 → 2.6.0). The `filters` argument of `dolibarr_list` was
  documented to the model as "filter by field values", but its keys were merged
  straight into the HTTP query string — and Dolibarr's REST router binds only
  the parameters an endpoint declares, discarding the rest in silence. Looking
  up a third party by e-mail returned a different company, and the tool
  reported success. Filters that an endpoint cannot bind are now translated to
  `sqlfilters`, and one it cannot express at all is refused rather than
  quietly ignored. Full details in the runtime's own changelog.

### Changed
- Pinned dependency versions: MCP runtime 2.6.0, audit library 1.1.0,
  SQL library 1.1.1.

## [1.5.1] - 2026-09-28

### Fixed
- **OAuth consent screen: presentation.** The page a user lands on when
  claude.ai asks for access was laid out badly. It runs with `NOREQUIREMENU`,
  and without a menu Dolibarr still builds its table layout: `#id-right`
  stayed a table cell that shrinks to its content, so `margin: auto` had no
  width to share and the card sat against the left edge. The two wrappers are
  now given a real width for this page only, and the consent card is a proper
  centred panel.

  Along with it: the title no longer carries the focus ring Dolibarr draws
  after focusing its `tabindex="-1"` heading — the heading is not interactive,
  so the ring said nothing — the padlock is legible instead of near-white, and
  the two buttons are explicit rather than inheriting a theme style that
  rendered the accept button as white text on a pale background.

  Nothing changed in the flow itself: the form, its CSRF token, the parameters
  it carries and the codes it issues are untouched.

## [1.5.0] - 2026-09-04

### Added
- **MCP call log, rate limit and alerting**, in a new admin tab *"MCP activity"*.

  Dolibarr permissions say *what* a user may read; they say nothing about *how
  much*. An employee entitled to look up customers one at a time is not thereby
  entitled to pull the whole customer base in an afternoon. This adds the
  missing half.

  - **Call log** — one row per MCP call: who, which tool, with which arguments,
    duration and outcome. Tool *results* are never stored; arguments are, since
    they are what makes a call auditable, and can be switched off.
  - **Rate limit** — a per-user cap on tool calls over a rolling window, with
    refusals answering in numbers the agent can act on. Only tool calls count,
    and refused calls are recorded but not counted.
  - **Email alert** — warns an administrator when a user's volume stands out,
    with a per-user cooldown so one intensive session sends one mail.

  The limit and the alert are off by default; logging is on. Shared with Dalfred
  through the new `dolibarr-mcp-audit` library.

## [1.4.1] - 2026-09-02

### Fixed
- **`CAST(x AS DECIMAL(20,6))` was refused as a syntax error** by the read-only
  SQL tool. `CAST` itself worked — `DECIMAL(20)` and `CHAR(10)` went through —
  the comma inside the type parameters did not, which made formatting amounts
  with a scale impossible. Types with a scale are accepted now, including
  inside aggregates and `CONVERT`. Everything else still fails closed.

## [1.4.0] - 2026-09-02

### Added
- **New MCP tool `dolibarr_environment`.** An agent can now ask what it is
  connected to instead of finding out through calls that fail: Dolibarr and PHP
  versions, the emMCP version serving the session, the MCP server version, the
  list of enabled Dolibarr modules, the entity, whether multicompany is active,
  and whether read-only SQL was granted to it.

  For the agent, knowing the Dolibarr version and the installed modules means
  adapting its calls instead of discovering absent endpoints one refusal at a
  time. For an administrator, asking "which version of the module is this
  install running?" now has an answer.

  Ungated on purpose — knowing what you are connected to should not itself
  require a grant — and takes no parameters.

## [1.3.3] - 2026-08-28

### Fixed
- **Module number moved to 491412.** 1.3.2 moved it off emSmartFill's 491409 and
  onto 491410 — which belongs to admdashboard, so it simply traded one collision
  for another. The authoritative list of taken numbers is the E-dem registry in
  `dolibarr/docs/INVENTAIRE-MODULES.md`, not what is visible from one repository:
  491410 and 491411 look free from here but belong to modules living in other
  project directories. 491412 is the first genuinely free number, and the
  registry now records it.

### Upgrade note
Only the developer's own installation is affected, and only if 1.3.2 was
installed. Remove both stale permission rows before upgrading:

```sql
DELETE FROM llx_user_rights      WHERE fk_id IN (491410, 49141001);
DELETE FROM llx_usergroup_rights WHERE fk_id IN (491410, 49141001);
DELETE FROM llx_rights_def       WHERE module = 'emmcp' AND id IN (491410, 49141001);
```

Then open the emMCP admin page: the migration recreates the right as 49141201.

## [1.3.2] - 2026-08-28

### Fixed
- **Module number collision with emSmartFill.** Both declared `numero = 491409`,
  so the SQL permission was listed inside emSmartFill's block on the user
  permissions screen instead of emMCP's. emMCP moves to 491410 — emSmartFill is
  already distributed, this one is not.
- **Permission id no longer computed as `numero + 1`.** That produces a value
  which is itself a valid module number, so it collides with whatever module
  owns it; it now uses Dolibarr's `numero . sprintf("%02d", …)` convention.
- **The module is named "emMCP" on the permissions screen**, not "Emmcp": the
  `Module491410Name` / `Module491410Desc` keys were missing, so Dolibarr fell
  back to the class name.

### Upgrade note
A Dolibarr that had 1.3.1 or earlier installed keeps the old permission row
(id 491410) alongside the new one, showing the SQL permission twice. It is
granted to nobody, so it can be removed safely:

```sql
DELETE FROM llx_rights_def WHERE id = 491410 AND module = 'emmcp';
```

No migration does this automatically: the module has not been distributed, so
the only affected installation is the developer's own.

## [1.3.1] - 2026-08-26

### Fixed
- **Schema exploration is usable again.** `dolibarr_sql_schema` called without a
  table returned every column of every table — large enough that the server cut
  it at 200 tables in alphabetical order, so common tables were missing from the
  answer with nothing saying so. It now returns table names only, complete.
  Passing an exact table name returns that table instead of everything sharing
  its prefix.
- **`SELECT *` works on ordinary tables.** It was refused on principle; the
  columns it stands for are now resolved and checked, so it is allowed where
  nothing sensitive is exposed and refused — naming the offending column — where
  something is.
- **`duration_ms` reports a real duration** instead of 0 for anything fast.

## [1.3.0] - 2026-08-25

### Changed
- **No more dedicated MySQL account.** Read-only SQL access ran on a separate
  SELECT-only database account, which had to be created by hand and whose
  grants were verified at every connection. In practice that requirement meant
  the feature stayed switched off: nobody creates a second account, and those
  who try tend to reuse the Dolibarr one, which the module then refused.
  Queries now run on Dolibarr's own credentials — on a separate mysqli session,
  so the statement timeout, the SQL mode and the read-only transaction never
  leak into the application connection. The *Dedicated MySQL account* section
  has been removed from the admin tab, along with the `EMMCP_SQL_DB_USER` and
  `EMMCP_SQL_DB_PASSWORD` settings.
- **The guarantee is now entirely in software.** A READ ONLY transaction stops
  INSERT/UPDATE/DELETE but not DDL, so the server no longer enforces read-only
  on its own. What does: a lexer that refuses multi-statement text and
  executable comments, a real SQL parser with a whitelist of clauses, a policy
  over every table, column and function, and a final check that the statement
  starts with SELECT or WITH. A dedicated hardening suite of ~80 payloads (DDL
  in every form, statement smuggling, executable comments, INTO OUTFILE,
  locking reads, write keywords hidden in literals) must stay refused, and a
  matching set of complex legitimate queries — UNION, CTE, nested subqueries,
  aggregates — must stay accepted.
- **The admin tab is now named "MCP SQL access"**, and states plainly that
  queries use the Dolibarr connection and what stops a write.

### Added
- The SQL layer moved to the shared `dolibarr-mcp-sql` library, so emMCP and
  Dalfred run the exact same code. A fix on one benefits the other. Each module
  keeps its own tables, constants and Dolibarr right, so both can be installed
  side by side on a single instance.

All notable changes to the emMCP module will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.2.0] - 2026-07-28

### Added
- **Read-only SQL access for reporting.** Two new MCP tools, `dolibarr_sql_query`
  and `dolibarr_sql_schema`, let a remote MCP agent inspect the database schema
  and run reporting queries — `SELECT`, `WITH`/CTE, `JOIN`, subqueries,
  aggregates and `UNION` — across core and third-party module tables.
- New admin tab **SQL access** to enable the feature, set the limits (rows,
  timeout, response size), grant it per user, and review the audit trail.
- New Dolibarr right `emmcp -> sqlquery -> read`, granted to nobody by default.
- Optional read-only database credentials (`EMMCP_SQL_DB_USER`,
  `EMMCP_SQL_DB_PASSWORD`) so the server itself can enforce read-only access.
- Audit trail in `llx_emmcp_sql_audit`: user, timestamp, query hash and
  truncated text, duration, row count, outcome. Query *results* are never
  stored, and `EMMCP_SQL_AUDIT_HASH_ONLY` reduces the record to its hash.

### Security
- **Disabled by default and off unless every condition holds**: the global flag
  `EMMCP_SQL_ENABLED`, the Dolibarr right, an explicit per-user opt-in, and no
  active multicompany module. Holding an API key is never sufficient.
- When access is not granted the SQL tools are **not listed at all**, rather
  than listed and refusing.
- Queries go through a fail-closed lexer and a parse-tree validator: one
  statement only; no writes or DDL; no locking reads (`FOR UPDATE`, `FOR SHARE`,
  `LOCK IN SHARE MODE`); no executable comments (`/*! … */`, `/*M! … */`) nor
  optimizer hints (`/*+ … */`), which the server honours while the parser drops
  them; no credential columns anywhere in the tree, matched by exact name and by
  fragment so `smtp_password` and `access_token` are caught as well as `api_key`;
  no `SELECT *` on any table, `COUNT(*)` excepted; no `SLEEP`/`BENCHMARK`/
  `LOAD_FILE`/lock functions.
- **Queries are confined to the current database**: a name qualified by a
  database (`other_db.llx_societe`) is refused, so an instance sharing a MySQL
  server with other Dolibarr installs cannot read them.
- Auth, session and configuration tables (`llx_const`, `llx_session`,
  `llx_emmcp_oauth_*`, `llx_oauth_token`) are unreachable, as are the system
  databases.
- **A dedicated database account is required, and its privileges are verified.**
  There is no fallback to Dolibarr's own account, and on every connection the
  module reads `SHOW GRANTS` and refuses to run unless the account holds only
  global `USAGE` and `SELECT` on this database — a *dedicated* account is not
  necessarily a *restricted* one. Grant lines carry the account password hash,
  so none is ever logged or surfaced.
- Execution runs on a dedicated connection inside a read-only transaction, with
  a normalised `sql_mode` and a statement timeout — both verified, and a failure
  to apply either aborts rather than degrades — plus server-imposed row and byte
  caps, applied while streaming results rather than after materialising them.
  Database errors are never relayed verbatim to the model.
- A successful schema introspection that could not be recorded in the audit
  trail is withheld, matching the rule already applied to successful queries.
- The release ZIP is reproducible (fixed timestamps, sorted entries), and the
  build refuses to bundle a dirty or unexpected version of either sibling
  repository.
- Dependency update: `guzzlehttp/guzzle` raised to 7.15.2 in the embedded MCP
  package, clearing four security advisories.

### Upgrade notes
- The new tables and the new right are created automatically on the first call
  after the files are uploaded — no disable/enable cycle needed.
- The feature stays **off** after upgrading. An administrator must enable it and
  grant it per user. Note that being an administrator does not by itself grant
  the right.

## [1.1.0] - 2026-07-16

### Changed
- **Internal refactor onto the shared `dolibarr-mcp-oauth` library.** The OAuth
  2.1 authorization server, the HTTP authentication resolution, and the URL/
  code-block helpers now come from the shared library (embedded in the ZIP),
  instead of a copy local to this module. **No functional change:** identical
  endpoints, routes, tables (`llx_emmcp_oauth_*`), status codes, OAuth error
  identifiers and discovery metadata — existing connectors keep working with no
  re-consent or re-registration.

## [1.0.1] - 2026-07-08

### Changed
- Mise à jour du paquet serveur MCP embarqué (`dolibarr-mcp-server`) avec les
  corrections issues de l'usage réel :
  - le filtre `fields` renvoie désormais un avertissement `unknown_fields`
    (avec la liste des champs réellement disponibles) au lieu d'un résultat
    vide trompeur quand aucun des champs demandés n'existe — utile par ex.
    sur `/tasks/{id}/timespent` dont les champs sont préfixés
    `timespent_line_*` ;
  - documentation des champs requis pour la création de tâches
    (`ref` + `fk_project`) et de propositions (`socid` + `date`), et des
    noms de champs corrects pour la lecture du temps passé.

## [1.0.0] - 2026-07-06

Première version stable.

### Added
- **Page « À propos »** (onglet dédié) : version, éditeur, licence,
  compatibilité, liste des fonctionnalités et liens utiles.

### Changed
- **Refonte de la page de configuration** : blocs de code lisibles avec
  **boutons « copier »** natifs (URL de l'endpoint, URL du connecteur,
  commande Claude Code, `mcp.json`), présentation par cas d'usage
  (claude.ai / Claude Code / client générique), et l'option connecteur
  claude.ai mise en avant comme recommandée.
- **Traductions FR et EN complètes** pour toute l'interface.

### Fixed
- Affichage du bloc `mcp.json` qui montrait des `\n` littéraux au lieu de
  retours à la ligne (échappement HTML incorrect).

## [0.2.1] - 2026-07-06

### Fixed
- **Authentification OAuth impossible derrière Apache + PHP-FPM** : le header
  HTTP `Authorization` était supprimé avant d'atteindre PHP, si bien que le
  jeton Bearer émis à un connecteur claude.ai n'arrivait jamais à `mcp.php`
  (401 « Impossible de se connecter » côté Claude, après un consentement
  pourtant réussi). Ajout d'un `.htaccess` qui réexpose le header à PHP
  (`RewriteRule … E=HTTP_AUTHORIZATION` + `SetEnvIfNoCase`). Sans effet sur
  les serveurs qui transmettent déjà le header (nginx, `CGIPassAuth On`).
  L'authentification par clé API directe n'était pas affectée sur les serveurs
  qui transmettent l'en-tête, mais l'était par le même mécanisme là où il est
  supprimé.

## [0.2.0] - 2026-07-06

Première release packagée — proof of concept installable.

### Added
- **Endpoint MCP distant** (`mcp.php`) exposant l'instance Dolibarr comme
  serveur MCP (Model Context Protocol, transport Streamable HTTP) : une
  requête HTTP = un message JSON-RPC, sans démon ni ReactPHP, compatible
  Apache/PHP-FPM. Consommable par Claude Code, l'API Anthropic
  (paramètre `mcp_servers`) et tout client MCP compatible HTTP.
- **20 outils Dolibarr** issus du paquet `dolibarr-mcp-server` (embarqué) :
  CRUD générique sur les ressources REST, explorateur d'API dynamique
  (lecture du Swagger/OpenAPI de l'instance), documents, lignes, actions
  métier, extrafields, contacts, projets, génération de fichiers.
- **5 guides d'usage servis en resources MCP** (`dolibarr://guide/*`) :
  règles essentielles, référence des outils, guides métier, workflows,
  référence des champs et limitations de l'API. Tout client MCP reçoit
  ainsi la même connaissance Dolibarr, sans la redécouvrir.
- **Authentification par clé API Dolibarr** : en-tête `Authorization: Bearer`
  ou `DOLAPIKEY`. Les outils agissent via l'API REST avec les permissions
  de l'utilisateur porteur de la clé.
- **Authentification OAuth 2.1** (`oauth.php`) pour les connecteurs
  personnalisés claude.ai (qui n'acceptent qu'OAuth) : métadonnées de
  ressource (RFC 9728) et de serveur d'autorisation (RFC 8414),
  enregistrement dynamique de client (RFC 7591), code d'autorisation avec
  PKCE S256 obligatoire, rotation des refresh tokens. L'utilisateur se
  connecte avec son compte Dolibarr et donne son consentement ; aucune
  clé à copier. Les tokens ne sont stockés qu'en hash sha256.
- **Page de configuration** listant l'URL de l'endpoint, les métadonnées
  OAuth, l'état des prérequis et des exemples de configuration client
  (Claude Code, mcp.json, connecteur claude.ai).

### Notes
- Prérequis : module **API REST** Dolibarr activé.
- Compatibilité : Dolibarr 16.x → 21.x, PHP 8.1+.
- Le paquet serveur MCP et ses dépendances Composer sont embarqués dans
  le zip (`vendor/dolibarr-mcp-server/`) : rien à installer côté client.
