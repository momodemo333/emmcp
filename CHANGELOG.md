# Changelog

All notable changes to the emMCP module will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
