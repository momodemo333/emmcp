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
  extrafields, contacts, projets, génération de fichiers), plus deux tools
  de **requêtes SQL en lecture seule** désactivés par défaut (voir ci-dessous).

## Requêtes SQL en lecture seule (optionnel, désactivé par défaut)

Deux tools supplémentaires — `dolibarr_sql_schema` et `dolibarr_sql_query` —
permettent à l'agent d'introspecter le schéma et d'exécuter des requêtes de
reporting (`SELECT`, `WITH`/CTE, `JOIN`, sous-requêtes, agrégats, `UNION`) sur
les tables métier, y compris celles des modules tiers.

Contrairement aux autres tools, qui passent par l'API REST et héritent donc des
permissions Dolibarr de l'appelant, une requête SQL n'hérite de rien. L'accès
est donc reconstruit explicitement et **quatre conditions doivent toutes être
réunies** :

1. la fonctionnalité est activée globalement (onglet **Accès SQL** de la
   configuration du module) ;
2. l'utilisateur détient le droit Dolibarr *Exécuter des requêtes SQL en lecture
   seule via MCP* — **être administrateur ne suffit pas**, le droit doit être
   attribué ;
3. l'utilisateur est explicitement coché dans la liste de l'onglet Accès SQL ;
4. le module multicompany n'est pas actif (ou l'exception a été activée) — une
   requête SQL traverse les lignes de toutes les entités, elle ne peut pas être
   filtrée par entité de façon fiable.

Tant que ces conditions ne sont pas réunies, les deux tools **n'apparaissent pas
du tout** dans la liste des tools exposés au client MCP.

⚠️ **Accorder ce droit donne une lecture large de la base**, très au-delà des
permissions métier habituelles de l'utilisateur (marges, salaires, ensemble des
tiers sans restriction commerciale). À réserver aux profils de type
direction/contrôle de gestion.

Garde-fous en place : une seule instruction par appel, aucune écriture ni DDL,
aucune lecture verrouillante (`FOR UPDATE`, `FOR SHARE`, `LOCK IN SHARE MODE`),
colonnes de credentials refusées où qu'elles apparaissent — noms exacts et
motifs, donc `smtp_password` ou `access_token` aussi bien que `api_key` —,
**`SELECT *` interdit sur toute table** (`COUNT(*)` reste autorisé), requêtes
limitées à la base courante (`autre_base.llx_societe` refusé), commentaires
exécutables et optimizer hints refusés, tables d'authentification et de session
inaccessibles, exécution sur une connexion dédiée en transaction de lecture
seule avec un `sql_mode` normalisé et un timeout obligatoire, plafonds de lignes
et de taille imposés par le serveur, et journal d'audit
(`llx_emmcp_sql_audit`) qui n'enregistre **jamais** les résultats.

L'agent doit donc nommer les colonnes dont il a besoin ; c'est à cela que sert
le tool d'introspection du schéma.

### Compte MySQL dédié — prérequis obligatoire

L'accès SQL exige un **compte MySQL distinct de celui de Dolibarr, ne disposant
que du privilège `SELECT`**, à renseigner dans l'onglet Accès SQL. Tant qu'il
n'est pas configuré, aucune requête n'est exécutée, même le commutateur activé.

Ce n'est pas une précaution facultative : une transaction en lecture seule
empêche les écritures de données mais **pas le DDL** (`CREATE`, `DROP`, `ALTER`
provoquent un commit implicite). Un compte restreint au `SELECT` est la seule
configuration où c'est le serveur, et non le code applicatif, qui impose la
lecture seule. Le module refuse d'ailleurs la connexion si le serveur
authentifie malgré tout le compte applicatif.

Le module **vérifie ces privilèges** plutôt que de les supposer : à chaque
connexion il lit `SHOW GRANTS` et refuse de fonctionner si le compte détient
autre chose que `USAGE` global et `SELECT` sur cette seule base. Un compte avec
`SELECT` global, `INSERT`, `CREATE`, `EXECUTE`, `FILE`, `GRANT OPTION` ou un rôle
est rejeté — un compte *dédié* n'est pas nécessairement un compte *restreint*.

Le compte ne doit donc avoir ni `EXECUTE`, ni `FILE`, ni aucun privilège DML ou
DDL :

```sql
CREATE USER 'dolibarr_ro'@'%' IDENTIFIED BY '<mot de passe>';
GRANT SELECT ON `votre_base`.* TO 'dolibarr_ro'@'%';
```

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
- Pas de gestion multi-entité (multicompany). L'accès SQL en lecture seule est
  d'ailleurs refusé hors entité 1 tant que l'exception n'est pas activée.
- Portée OAuth unique (`dolibarr`) : pas encore de granularité par scope.
- Les requêtes SQL en lecture seule nécessitent MySQL ou MariaDB (PostgreSQL
  n'est pas pris en charge par cette fonctionnalité).
