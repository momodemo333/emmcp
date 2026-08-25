# Changelog

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
