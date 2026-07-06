# Changelog

All notable changes to the DoliMCP module will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
