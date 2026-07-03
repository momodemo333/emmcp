# DoliMCP — Serveur MCP distant pour Dolibarr (POC)

Expose une instance Dolibarr comme **serveur MCP** (Model Context Protocol,
transport Streamable HTTP) consommable par Claude Code, l'API Anthropic
(`mcp_servers`), et tout client MCP compatible HTTP.

## Fonctionnement

- Endpoint unique : `https://<instance>/custom/dolimcp/mcp.php`
- **Par requête** (PHP-FPM/Apache) : pas de daemon, pas de ReactPHP.
  Chaque POST JSON-RPC est traité par le SDK PHP officiel MCP (`mcp/sdk`),
  les sessions MCP sont persistées dans `documents/dolimcp/sessions/`.
- **Authentification** : clé API d'un utilisateur Dolibarr, via
  `Authorization: Bearer <clé>` (recommandé) ou header `DOLAPIKEY`.
  Les tools agissent ensuite via l'API REST Dolibarr avec les permissions
  de cet utilisateur.
- **Tools** : les 20 tools du paquet
  [dolibarr-mcp-server](https://github.com/momodemo333/dolibarr-mcp-server)
  (CRUD générique, explorateur d'API, documents, lignes, actions métier,
  extrafields, contacts, projets, génération de fichiers).

## Prérequis (POC)

- Module **API REST** Dolibarr activé.
- Module **Dalfred** présent (le paquet MCP embarqué de Dalfred est réutilisé).
  Une release standalone embarquera son propre `vendor/`.

## Exemple : Claude Code

```bash
claude mcp add dolibarr --transport http \
  https://<instance>/custom/dolimcp/mcp.php \
  --header "Authorization: Bearer VOTRE_CLE_API"
```

## Limites connues du POC

- Pas encore d'OAuth 2.1 → non utilisable comme connecteur claude.ai
  web/desktop (qui n'accepte pas les headers custom). Prévu en phase 2.
- Découverte des tools re-scannée à chaque requête (pas de cache PSR-16).
- Pas de gestion multi-entité (multicompany).
