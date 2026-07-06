# emMCP — Serveur MCP distant pour Dolibarr (POC)

Expose une instance Dolibarr comme **serveur MCP** (Model Context Protocol,
transport Streamable HTTP) consommable par Claude Code, l'API Anthropic
(`mcp_servers`), et tout client MCP compatible HTTP.

## Fonctionnement

- Endpoint unique : `https://<instance>/custom/emmcp/mcp.php`
- **Par requête** (PHP-FPM/Apache) : pas de daemon, pas de ReactPHP.
  Chaque POST JSON-RPC est traité par le SDK PHP officiel MCP (`mcp/sdk`),
  les sessions MCP sont persistées dans `documents/emmcp/sessions/`.
- **Authentification**, deux voies :
  - **Clé API** d'un utilisateur Dolibarr, via `Authorization: Bearer <clé>`
    (recommandé) ou header `DOLAPIKEY` — pour Claude Code, l'API Anthropic,
    et tout client MCP acceptant un header custom.
  - **OAuth 2.1** (`oauth.php`) — pour les connecteurs claude.ai web/desktop
    qui n'acceptent qu'OAuth : découverte automatique (RFC 9728 + RFC 8414),
    enregistrement dynamique du client (RFC 7591), code d'autorisation avec
    PKCE S256, rotation des refresh tokens. L'utilisateur se connecte avec
    son compte Dolibarr et consent ; aucune clé à copier.
  Dans les deux cas, les tools agissent via l'API REST Dolibarr avec les
  permissions de l'utilisateur.
- **Tools** : les 20 tools du paquet
  [dolibarr-mcp-server](https://github.com/momodemo333/dolibarr-mcp-server)
  (CRUD générique, explorateur d'API, documents, lignes, actions métier,
  extrafields, contacts, projets, génération de fichiers).

## Prérequis (POC)

- Module **API REST** Dolibarr activé.
- Module **Dalfred** présent (le paquet MCP embarqué de Dalfred est réutilisé).
  Une release standalone embarquera son propre `vendor/`.

## Exemple : Claude Code (clé API)

```bash
claude mcp add dolibarr --transport http \
  https://<instance>/custom/emmcp/mcp.php \
  --header "Authorization: Bearer VOTRE_CLE_API"
```

## Exemple : connecteur claude.ai (OAuth)

Dans claude.ai : Paramètres → Connecteurs → « Ajouter un connecteur
personnalisé », URL `https://<instance>/custom/emmcp/mcp.php`. Claude
découvre l'OAuth, ouvre la page de connexion Dolibarr et l'écran de
consentement, puis se connecte — sans clé à saisir.

## Endpoints OAuth (`oauth.php`)

| Route | Rôle |
|-------|------|
| `/.well-known/oauth-protected-resource` | Métadonnées de la ressource (RFC 9728) — pointées par le `WWW-Authenticate` du 401 de `mcp.php` |
| `/.well-known/oauth-authorization-server` et `/openid-configuration` | Métadonnées du serveur d'autorisation (RFC 8414) |
| `/register` | Enregistrement dynamique de client (RFC 7591) |
| `/authorize` | Login Dolibarr natif + écran de consentement |
| `/token` | Échange de code (PKCE S256) et rotation des refresh tokens |

Tokens stockés en base sous forme de hash sha256 uniquement
(`llx_emmcp_oauth_token`) ; access token 1 h, refresh token 30 j.

## Limites connues du POC

- Découverte des tools re-scannée à chaque requête (pas de cache PSR-16).
- Pas de gestion multi-entité (multicompany).
- Portée OAuth unique (`dolibarr`) : pas encore de granularité par scope.
