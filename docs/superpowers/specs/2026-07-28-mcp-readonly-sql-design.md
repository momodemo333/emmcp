# Requêtes SQL en lecture seule via le MCP Dolibarr — conception

Date : 2026-07-28
Statut : validé (arbitrages tranchés par Morgan le 2026-07-28)
Modules concernés : `dolibarr-mcp-server` (runtime partagé, MIT), `emmcp` (livrable prioritaire, GPL-3.0+), `dalfred` (réutilisation)

---

## 1. Objectif

Permettre à un agent MCP distant (claude.ai, Claude Code) d'introspecter le schéma
de la base Dolibarr et d'exécuter des requêtes de reporting complexes — `SELECT`,
`WITH`/CTE, `JOIN`, sous-requêtes, agrégats, `UNION` — sur l'ensemble des tables
métier (core et modules tiers), **sans aucune écriture possible**.

La fonctionnalité est désactivée par défaut, s'active explicitement dans l'admin
emMCP, et exige un droit Dolibarr accordé nommément à chaque utilisateur.

## 2. La contrainte qui commande la conception

`dolibarr-mcp-server` **n'a aucun accès base de données et ne connaît pas
l'utilisateur**. Son unique contexte d'exécution est `ConnectionConfig(baseUrl,
apiKey)` ; tous ses tools passent par HTTP sur l'API REST Dolibarr, et héritent
donc *implicitement* des permissions Dolibarr du porteur de la clé.

Un tool SQL est le premier tool du runtime qui ne peut pas s'appuyer sur l'API
REST pour son autorisation. Toute la conception consiste à reconstruire
explicitement ce que les autres tools obtiennent gratuitement.

Seconde contrainte structurante : la découverte des tools se fait par scan
d'attributs `#[McpTool]` sur tout `src/`. Un fichier posé dans `src/Tools/` est
exposé à **tout** porteur de clé API, sans opt-in.

## 3. Architecture retenue — capacité injectée par l'hôte

Trois options ont été examinées.

**Option A — endpoint REST custom côté module + tool MCP mince.** Pattern déjà
éprouvé (`dolibarr_files_create` → `api_dalfred.class.php`). Écartée : elle crée
une nouvelle surface HTTP authentifiée à durcir, fait transiter le SQL dans un
corps de requête loopback (donc potentiellement dans les logs du serveur web), et
impose de dupliquer la glue REST entre emMCP et Dalfred.

**Option C — pas de SQL libre, extension de `sqlfilters`.** Écartée : ni CTE, ni
`JOIN`, ni `UNION`, ni agrégat. Ne répond pas au besoin.

**Option B — capacité DB injectée par l'hôte dans le container. Retenue.**
L'hôte (`emmcp/mcp.php`) résout l'utilisateur Dolibarr, vérifie flag global,
droit et opt-in, puis construit un objet capacité qu'il injecte dans le
container ; le runtime ne fait qu'appeler une interface.

L'avantage décisif est que le **deny-by-default devient structurel** :
`bin/server.php` et `public/index.php` n'injectent rien, donc le tool n'existe
pas hors contexte Dolibarr, et aucun fichier `.env` ne peut ouvrir une porte SQL.

### Répartition des responsabilités

| Couche | Où | Contenu |
|---|---|---|
| Validation SQL | `dolibarr-mcp-server/src/Sql/` | Lexer, validateur AST, politique. Pur PHP, aucune dépendance Dolibarr, testable hors Dolibarr. |
| Contrat d'exécution | `dolibarr-mcp-server/src/Sql/` | `SqlCapabilityInterface` implémentée par l'hôte. |
| Tools MCP | `dolibarr-mcp-server/src/Tools/Gated/` | `dolibarr_sql_schema`, `dolibarr_sql_query`. |
| Exécution + autorisation + audit | `emmcp/class/` | Connexion dédiée, transaction read-only, droits Dolibarr, journal. |

## 4. Le validateur SQL

### 4.1 Choix du parser

`phpmyadmin/sql-parser`, le plus rigoureux, est **inutilisable** : GPL-2.0-or-later
(incompatible avec le package MIT, qu'il relicencierait de fait) et PHP ^8.2 sur
sa version courante alors que le package cible 8.1. `sqlftw` est propriétaire.
`doctrine/sql-formatter` est MIT mais son tokenizer est marqué `@internal`.

**Retenu : `greenlion/php-sql-parser` v4.7.0** — BSD-3-Clause (compatible MIT),
PHP ≥ 5.3.2, aucune dépendance runtime, 12,6 M téléchargements.

Validation empirique effectuée avant engagement : `WITH`, `WITH RECURSIVE`,
`UNION` et sous-requêtes sont correctement analysés, et l'arbre expose
récursivement des nœuds `colref` / `table` / `function` avec un champ
`no_quotes.parts` qui donne les identifiants débackticés — exactement ce qu'exige
l'analyse des références de colonnes.

### 4.2 Pourquoi un lexer maison *en plus* du parser

Deux comportements du parser, confirmés par test, interdisent de lui faire
confiance seul :

1. `SELECT 1; DROP TABLE llx_societe` n'est **pas** rejeté : le parser fusionne et
   retourne les sections `SELECT, DROP`. Qui regarderait `$tree['SELECT']`
   raterait le `DROP`.
2. `SELECT 1 /*!32302 , (SELECT api_key FROM llx_user LIMIT 1) */` est analysé
   comme un simple `SELECT 1` : **le contenu du commentaire exécutable est
   silencieusement ignoré par le parser alors que MySQL l'exécute.** C'est le
   bypass exact qui affecte aujourd'hui le toolkit Dalfred.

Le lexer (`SqlLexer`) est donc une pré-passe *fail-closed* qui travaille sur le
texte brut avant tout parsing, avec une machine à états gérant correctement les
chaînes simples et doubles (et leurs échappements), les identifiants backticés,
les commentaires `--`, `#` et `/* */`, et les littéraux hexadécimaux et binaires.

Il rejette : toute séquence `/*!` (sans chercher à l'interpréter), tout
point-virgule hors chaîne/commentaire suivi de contenu significatif, tout
commentaire non terminé, et toute entrée non-UTF-8.

### 4.3 Le pipeline de validation

```
SQL brut
  └─> SqlLexer            fail-closed : /*!, multi-statement, commentaires, encodage
  └─> PHPSQLParser        exception ⇒ refus (jamais « accepté par défaut »)
  └─> whitelist sections  toute clé hors {SELECT, FROM, WHERE, GROUP, HAVING,
                          ORDER, LIMIT, WITH, UNION, UNION ALL, JOIN, …} ⇒ refus
  └─> SqlPolicy (AST)     tables, colonnes, fonctions, SELECT *
  └─> LIMIT               injecté si absent, plafonné s'il est présent
```

Chaque étape ne peut qu'ajouter un refus. Une exception du parser vaut refus, et
un arbre dont une section n'est pas reconnue vaut refus.

### 4.4 Analyse AST — tables, colonnes, fonctions

L'arbre est parcouru récursivement, en collectant chaque nœud portant un
`expr_type`. Sont recensés :

- `table` → nom de table (segment terminal de `no_quotes.parts`, ce qui neutralise
  la qualification par base : `gsedem.llx_user` est traité comme `llx_user`) ;
- `temporary-table` → nom de CTE, à **exclure** du contrôle de tables (sinon un CTE
  nommé `x` serait refusé faute de préfixe `llx_`) ;
- `colref` → nom de colonne (segment terminal, ce qui neutralise l'alias :
  `u.api_key` est traité comme `api_key`) ;
- `function` / `aggregate_function` → nom de fonction, via `base_expr`.

Les colonnes sont contrôlées **par leur nom terminal, indépendamment de la table**.
C'est volontairement plus strict qu'une résolution alias → table : une requête
lisant une colonne nommée `api_key` dans une table tierce sera refusée. Ce choix
évite une résolution d'alias fragile sur un point de sécurité, au prix de faux
positifs rares et facilement contournables par le renommage explicite.

## 5. Politique de sécurité

### 5.1 Denylist de tables

Interdites en toutes circonstances : `llx_const`, `llx_session`,
`llx_oauth_token`, `llx_emmcp_oauth_client`, `llx_emmcp_oauth_token`, ainsi que
tout `llx_dalfred_*` et les tables d'audit du dispositif lui-même.

Les bases système `information_schema`, `mysql`, `performance_schema` et `sys`
sont interdites au SQL libre ; l'introspection passe exclusivement par le tool
schéma dédié, qui utilise ses propres requêtes paramétrées.

Toute table doit par ailleurs porter le préfixe de la base (`MAIN_DB_PREFIX`,
typiquement `llx_`), fourni par l'hôte à la politique.

### 5.2 Denylist de colonnes — noms exacts et motifs

Bloquées quelle que soit la table : les noms exacts (`pass`, `pass_crypted`,
`api_key`, `token_hash`, `client_secret`, `code_challenge`, `private_key`,
`signature_key`…) **et** tout nom contenant, une fois les séparateurs
supprimés, l'un des fragments `password`, `passwd`, `passphrase`, `apikey`,
`token`, `secret`, `credential`, `privatekey`, `signaturekey`, `signingkey`.

La liste exacte seule ne couvrait que les orthographes auxquelles on avait
pensé, ce qui vaut peu face aux modules tiers : `smtp_password`, `apikey`,
`access_token` et `webhook_secret` passaient tous.

Ces motifs sont **volontairement fail-closed** : `token_count` et
`secretary_name` sont refusés bien qu'innocents. Restreindre les fragments assez
pour les laisser passer laisserait aussi passer de vrais secrets, et une colonne
illisible coûte une requête à reformuler là où une fuite est irréversible. Les
tests l'affirment explicitement pour que le compromis reste visible.

Ce mécanisme est ce qui permet de **garder `llx_user` interrogeable** pour ses
colonnes inoffensives (`rowid`, `login`, `lastname`, `firstname`), sans quoi les
jointures les plus banales du reporting Dolibarr — chiffre d'affaires par
commercial, documents par auteur — seraient cassées.

### 5.3 `SELECT *` — refusé partout

Refusé sur **toute** table, `alias.*` compris. `COUNT(*)` reste autorisé, et
l'exemption est limitée à `COUNT` et non aux fonctions en général, sinon
`SUM(*)` passerait.

La première version ne refusait l'étoile que si une table d'une liste connue
(`llx_user`, `llx_socpeople`…) était référencée. La revue a montré que cela ne
protège que ce à quoi on avait pensé : une table de module tiers du type
`llx_x_config` contenant une clé d'API était intégralement lisible. Une liste de
tables sensibles est une devinette sur le contenu de bases qu'on ne connaît pas.

La notion de « table sensible » a donc été **supprimée** plutôt que laissée en
place sans effet, y compris dans l'affichage admin.

### 5.4 Qualification par base de données — refusée

`SELECT ... FROM other_db.llx_societe` était accepté : l'analyse ne conservait
que le segment terminal de l'identifiant, donc la table était vérifiée comme si
elle appartenait à la base courante. Sur un serveur hébergeant plusieurs
Dolibarr, cela permettait d'en lire un autre.

Toute table qualifiée par une base est désormais refusée
(`SQL_QUALIFIED_TABLE`), de même que les colonnes à trois segments
(`SQL_QUALIFIED_COLUMN`). Ne pas dépendre des privilèges MySQL est délibéré :
le module ne les configure pas et ne peut pas les inspecter.

### 5.5 Lectures verrouillantes — refusées

`LOCK IN SHARE MODE` était rangé par le parser dans une section `OPTIONS` que la
whitelist acceptait, et `FOR SHARE` est absorbé dans l'alias de table sans
laisser la moindre trace dans l'arbre. `OPTIONS` est retirée de la whitelist, et
les clauses de verrouillage sont détectées sur une **copie masquée** de la
requête — chaînes, identifiants entre backticks et commentaires blanchis par
`SqlLexer::maskLiteralsAndComments()`. C'est ce masquage qui permet une
détection textuelle sans faux positif : `WHERE nom = 'FOR UPDATE'` reste
accepté.

### 5.4 Fonctions interdites

`SLEEP`, `BENCHMARK`, `GET_LOCK`, `RELEASE_LOCK`, `IS_FREE_LOCK`,
`IS_USED_LOCK`, `MASTER_POS_WAIT`, `SOURCE_POS_WAIT`, `LOAD_FILE`, `SYS_EXEC`,
`SYS_EVAL`. Détectées dans l'AST, pas par expression régulière.

### 5.5 Limites imposées côté serveur

| Limite | Défaut | Plafond dur | Constante |
|---|---|---|---|
| Lignes retournées | 200 | 5 000 | `EMMCP_SQL_MAX_ROWS` |
| Timeout requête | 5 s | 30 s | `EMMCP_SQL_TIMEOUT` |
| Taille de réponse | 256 Ko | — | `EMMCP_SQL_MAX_BYTES` |
| Longueur du SQL | 8 000 car. | — | — |

Le `LIMIT` est injecté s'il est absent, et plafonné s'il est présent.

## 6. Défense en profondeur côté base

Le validateur peut être contourné ; le moteur, non. Trois mesures, par ordre
d'importance.

**Connexion dédiée.** L'exécution ne passe pas par le `$db` Dolibarr partagé :
ouvrir une transaction en lecture seule dessus bloquerait le reste de la requête
PHP, à commencer par l'écriture de l'audit. Une connexion séparée est ouverte
pour la requête puis fermée, ce qui isole également le timeout de session.

**`START TRANSACTION READ ONLY`** avant exécution, `ROLLBACK` après. MySQL et
MariaDB refusent alors tout **DML** au niveau du moteur, quelle que soit la
finesse du contournement textuel.

⚠️ **Limite mesurée, pas théorique** : le test d'intégration adversarial a montré
qu'une transaction en lecture seule **n'arrête pas le DDL**. `CREATE`, `DROP` et
`ALTER` provoquent un *commit implicite* et s'exécutent malgré elle — un
`CREATE TABLE` émis dans une transaction READ ONLY a bien créé la table sur
MariaDB. Le moteur ne rend donc pas, à lui seul, la connexion en lecture seule.

Trois conséquences retenues :

1. La seule protection DDL réellement imposée par le serveur est un **compte
   MySQL ne portant que `SELECT`**, configurable via `EMMCP_SQL_DB_USER` et
   recommandé en production. Il ne peut pas être exigé d'un client Dolistore.
2. La gateway porte donc une **garde de dernier recours** : elle refuse tout ce
   qui ne commence pas par `SELECT` ou `WITH`. Ce n'est pas la validation
   principale — le lexer et l'analyse AST le sont — mais elle garantit que rien
   d'autre qu'une lecture n'atteint le driver, y compris depuis un futur chemin
   d'appel qui oublierait de valider.
3. Le test d'intégration vérifie explicitement le refus de `CREATE`, `DROP`,
   `ALTER`, `INSERT`, `DELETE`, `TRUNCATE`, `GRANT` et `SET GLOBAL`.

**Transport `mysqli`.** `mysqli_query` n'accepte qu'un seul statement par appel
(seul `multi_query` en accepte plusieurs) : la classe d'attaque multi-statement
disparaît au niveau du transport. Le toolkit Dalfred actuel, à l'inverse, utilise
un PDO sans `ATTR_EMULATE_PREPARES=false`, où les statements empilés sont
possibles.

**Timeout de session obligatoire.** `max_statement_time` (MariaDB, secondes) ou
`MAX_EXECUTION_TIME` (MySQL, millisecondes) selon le serveur détecté. Un échec
de ce réglage **avorte la connexion** au lieu d'être ignoré : les plafonds de
lignes et d'octets bornent la *sortie*, et ils sont appliqués pendant la lecture
des résultats, donc **après** que le serveur a fait le travail. Une jointure
cartésienne ou un tri sur une grosse table peut consommer des minutes de CPU
avant de renvoyer sa première ligne. Le timeout est la seule borne sur le temps
d'exécution.

**`sql_mode` normalisé.** Le validateur lit la requête comme le ferait une
session MySQL par défaut. Une session héritant d'`ANSI_QUOTES` interprète
`"api_key"` comme un identifiant là où le lexer a vu une chaîne : un littéral
masqué redevient une référence de colonne vivante. `NO_BACKSLASH_ESCAPES`
déplace de même les frontières de chaînes calculées par le lexer. Dans les deux
cas la validation décrirait une requête différente de celle qui s'exécute. Le
mode est donc positionné explicitement sur la connexion dédiée, puis **relu pour
vérification**, et un échec avorte.

Une constante optionnelle permet de fournir des identifiants MySQL en lecture
seule dédiés — recommandé en production, jamais requis, un client Dolistore ne
faisant pas d'action DBA.

## 7. Autorisation

Quatre conditions, toutes nécessaires :

1. **Flag global** `EMMCP_SQL_ENABLED`, défaut `0`. Volontairement **non déclaré**
   dans `$this->const` du descripteur, afin qu'un cycle disable/enable du module
   ne réinitialise pas le choix du client (pattern `DALFRED_MCP_EXTERNAL_ENABLED`).
2. **Droit Dolibarr** `emmcp->sqlquery->read`, déclaré dans `$this->rights` du
   descripteur, donc gérable dans l'écran de permissions natif.
3. **Opt-in par utilisateur** en base (`llx_emmcp_sql_permissions`), coché dans la
   page admin dédiée.
4. **Module actif** et endpoint MCP accessible.

Un cinquième garde-fou couvre le multi-entité : **dès que le module multicompany
est actif**, la requête est refusée, sauf `EMMCP_SQL_ALLOW_MULTIENTITY=1`.

La première version ne refusait que si l'entité courante n'était pas `1`, ce qui
était sans effet : `mcp.php` tourne en `NOSESSION` et reste donc sur l'entité 1,
alors que la requête SQL, elle, traverse les lignes de toutes les entités. Le
contrôle laissait précisément passer le cas dangereux. Le SQL libre ne peut pas
être filtré par entité de façon fiable, donc le refus est global.

### Deux comportements Dolibarr vérifiés, non supposés

**Être administrateur ne suffit pas.** `User::loadRights()` ne force que quelques
droits du module `user` pour les administrateurs ; tous les autres doivent être
attribués explicitement. Le droit `emmcp->sqlquery->read` n'est donc accordé à
personne tant qu'un administrateur ne l'assigne pas, y compris à lui-même. Le
test d'intégration l'affirme (`admin does NOT hold the right by default`) plutôt
que de le supposer.

**Le droit doit être propagé par migration.** Les droits sont écrits par
`init()` via `insert_permissions()`, et `init()` n'est pas rejoué lors d'une
mise à jour de fichiers. Sans propagation, le droit introduit en 1.2.0
n'existerait jamais chez un client existant : la page admin proposerait
d'accorder un droit que personne ne peut détenir, et tout appel SQL serait
refusé sans cause visible. La migration appelle donc `insert_permissions(0)` —
avec `$reinitadminperms = 0`, car passer `1` (ce que fait
`DolibarrModules::_init()`) réattribue tous les droits du module à tous les
administrateurs et annule silencieusement les révocations d'un client.

Piège connexe accepté en connaissance de cause : un cycle désactivation /
réactivation du module rejoue `_init()`, donc `insert_permissions(1)`, et
réattribue le droit SQL aux administrateurs. Ce n'est **pas exploitable ici**,
car l'accès exige aussi le flag global (non déclaré dans `$this->const`, donc
préservé) et l'opt-in par utilisateur (table non supprimée par `_remove()`). La
conjonction des conditions absorbe le défaut ; c'est précisément pourquoi elle
existe.

### Avertissement obligatoire

La page admin affiche, à côté de chaque case à cocher, un avertissement explicite :
accorder ce droit donne à l'utilisateur une **lecture large de la base**, très
au-delà de ses permissions métier Dolibarr habituelles (marges, masse salariale,
tous les tiers sans restriction commerciale). Cet avertissement est une exigence
de conception, pas un détail d'interface : c'est la seule chose qui empêche un
administrateur de cocher la case sans mesurer la portée.

Il faut noter le contraste avec l'existant Dalfred, où la seule barrière entre un
utilisateur non-admin et `llx_user.pass_crypted` est aujourd'hui une consigne en
français dans le prompt système. Aucune logique d'autorisation n'est reprise de là.

## 8. Audit

Table `llx_emmcp_sql_audit` : `entity`, `fk_user`, `date_creation`, `sql_hash`
(sha256 du SQL normalisé), `sql_text` (tronqué à 2 000 caractères),
`duration_ms`, `row_count`, `bytes`, `success`, `error_code`, `source`
(`mcp` | `dalfred`).

**Les résultats ne sont jamais stockés.** Le texte de la requête peut contenir des
données personnelles dans une clause `WHERE` ; une constante
`EMMCP_SQL_AUDIT_HASH_ONLY` permet de ne conserver que le hash.

L'écriture de l'audit se fait sur la connexion Dolibarr principale, hors de la
transaction en lecture seule, et un échec d'audit **fait échouer la requête** :
pas d'exécution non tracée.

## 9. Exposition conditionnelle du tool

Les tools vivent dans `src/Tools/Gated/`, un sous-dossier que `buildServer()`
exclut du scan de découverte quand aucune capacité n'est injectée. Le tool
**disparaît** alors de `tools/list` au lieu d'exister et de refuser — ce qui évite
d'en signaler l'existence et de polluer le contexte du modèle.

Si le SDK ne permet pas l'exclusion de sous-dossier de façon fiable, le repli est
un refus avec le code stable `SQL_CAPABILITY_UNAVAILABLE`. Les tests couvrent les
deux comportements.

## 10. Erreurs

Codes stables en `SCREAMING_SNAKE`, conformes à la convention du package :
`SQL_CAPABILITY_UNAVAILABLE`, `SQL_DISABLED`, `SQL_PERMISSION_DENIED`,
`SQL_MULTI_STATEMENT`, `SQL_NOT_READ_ONLY`, `SQL_FORBIDDEN_TABLE`,
`SQL_FORBIDDEN_COLUMN`, `SQL_FORBIDDEN_FUNCTION`, `SQL_STAR_NOT_ALLOWED`,
`SQL_QUALIFIED_TABLE`, `SQL_QUALIFIED_COLUMN`, `SQL_OPTIMIZER_HINT`,
`SQL_PARSE_ERROR`, `SQL_TOO_LONG`, `SQL_TIMEOUT`, `SQL_RESULT_TOO_LARGE`,
`SQL_EXECUTION_ERROR`, `SQL_MULTIENTITY_BLOCKED`.

Le message renvoyé au modèle est générique, le détail part en syslog. Deux
exceptions dans une liste blanche restreinte — « colonne inconnue », « table
inconnue » — remontent le nom en cause, information déjà accessible par le tool
d'introspection et nécessaire pour que le modèle se corrige seul.

## 11. Modèle de menaces

| Menace | Traitement |
|---|---|
| Écriture ou DDL déguisée (`;`, `/*! */`, `INTO OUTFILE`) | lexer + whitelist de sections + transaction READ ONLY + `mysqli` mono-statement |
| Exfiltration de secrets | denylist tables + denylist colonnes + interdiction `SELECT *` |
| Contournement des droits métier | flag + droit + opt-in + avertissement admin + audit |
| Déni de service (`SLEEP`, produit cartésien, verrous) | fonctions interdites + timeout serveur + `LIMIT` |
| Lecture de fichiers (`LOAD_FILE`, `LOAD DATA`) | lexer + fonctions interdites + `secure_file_priv` documenté |
| Fuite par messages d'erreur | codes stables, détail en syslog uniquement |
| Injection de prompt (une note de tiers dictant une requête) | toutes les défenses sont en dur, aucune dans le prompt |
| Multi-entité | refus si multicompany actif et entité ≠ 1 |
| Fuite par l'audit | jamais de résultats ; requête tronquée, option hash seul |
| Tool exposé sans capacité | absent de `tools/list`, repli en refus |

## 12. Fichiers touchés

### `dolibarr-mcp-server` (repo GitHub, MIT)

Nouveaux : `src/Sql/SqlLexer.php`, `src/Sql/SqlReadOnlyValidator.php`,
`src/Sql/SqlPolicy.php`, `src/Sql/SqlCapabilityInterface.php`,
`src/Sql/SqlExecutionResult.php`, `src/Sql/SqlValidationException.php`,
`src/Tools/Gated/SqlTools.php`.

Modifiés : `src/Bootstrap.php` (capacité optionnelle, exclusion du dossier gated),
`composer.json` (dépendance `greenlion/php-sql-parser`), `LLM.md`, `README.md`,
`CHANGELOG.md`.

Dette relevée au passage : la version annoncée par `Bootstrap` (`2.0.0`) est
incohérente avec le `CHANGELOG` (`2.1.0`), et la section `Resource Names` de
`LLM.md` n'est rattachée à aucun guide donc n'est servie à aucun client.

### `emmcp` (GPL-3.0+)

Nouveaux : `class/emmcpsqlcapability.class.php`, `class/emmcpsqlgateway.class.php`,
`class/emmcpsqlaudit.class.php`, `class/emmcpsqlpermissions.class.php`,
`class/emmcpmigrationhelper.class.php`, `class/emmcpmigrations.class.php`,
`admin/sql_access.php`, `sql/llx_emmcp_sql_audit.sql` (+ `.key.sql`),
`sql/llx_emmcp_sql_permissions.sql` (+ `.key.sql`).

Modifiés : `mcp.php` (résolution utilisateur, construction conditionnelle de la
capacité), `core/modules/modEmmcp.class.php` (droits, version, tables),
`lib/emmcp.lib.php` (onglet admin), `langs/{en_US,fr_FR}/emmcp.lang`, `Makefile`
(`CRITICAL_FILES`), `CHANGELOG.md`, `README.md`.

### `dalfred` (réutilisation, non bloquant pour la v1)

`src/MCP/DirectMcpBridge.php` : construction de la capacité et ajout du tool à la
liste `getToolClasses()` — codée en dur, et où `ProjectTools` a déjà été oublié.

### Migrations

emMCP n'a pas de `ModuleMigrationHelper`, et le porter tel quel l'exposerait au
paradoxe d'amorçage documenté dans la base de connaissances : le hook
`printCommonFooter` dépend du contexte `all` écrit par `init()`, donc la release
qui l'introduit exigerait un cycle disable/enable manuel.

Or emMCP n'a pas d'interface métier : ses seuls points d'entrée sont `mcp.php` et
ses pages admin. **La migration est donc déclenchée au démarrage de ces points
d'entrée**, sans hook ni contexte `all`. Pas de paradoxe d'amorçage, et migration
effective dès le premier appel MCP suivant l'upload des fichiers.

C'est une pratique réutilisable pour tout module sans UI, à verser dans
`~/dev-docs/dolibarr/db-migrations.md`.

## 13. Plan de tests (TDD strict, rouge d'abord)

### Niveau 1 — lexer et validateur (aucun mock, pur texte)

Corpus adversarial, chaque cas devant être **refusé** :
multi-statement ; commentaire exécutable `/*!` sous toutes ses formes ; commentaire
non terminé ; `INTO OUTFILE` et `INTO DUMPFILE` ; `LOAD_FILE` ; `SLEEP`,
`BENCHMARK`, `GET_LOCK` ; `SET`, `DO`, `CALL`, `HANDLER` ; `FOR UPDATE` et
`LOCK IN SHARE MODE` ; table interdite atteinte par sous-requête, par CTE, par
branche d'`UNION`, par alias, en backticks, par préfixe de base explicite
(`gsedem.llx_user`), en casse mixte ; colonne interdite sous toutes ces formes ;
`SELECT *` et `u.*` sur table sensible ; SQL trop long ; entrée non-UTF-8.

Corpus de non-régression, chaque cas devant être **accepté** — ce sont les faux
positifs actuels de Dalfred, qui ne doivent pas réapparaître :
point-virgule à l'intérieur d'une chaîne (`WHERE nom = 'a;b'`) ; mot-clé dans un
littéral (`WHERE nom = 'DROP SA'`) ; colonne ou alias nommé `create`, `update`,
`delete`, `into` ; `UNION` légitime ; `WITH RECURSIVE` ; agrégats et `HAVING`.

### Niveau 2 — politique et limites

`LIMIT` injecté quand absent, plafonné quand présent ; troncature de réponse ;
propagation du timeout ; préfixe de table configurable.

### Niveau 3 — tools MCP (capacité mockée)

Refus propre sans capacité ; codes d'erreur stables ; absence du tool dans
`tools/list` quand la capacité est nulle ; forme du JSON retourné.

### Niveau 4 — module (fakes DoliDB de `dolibarr-mcp-oauth`)

Les quatre conditions d'autorisation, chacune isolément bloquante ; écriture de
l'audit ; échec d'audit ⇒ échec de la requête ; blocage multi-entité.

### Niveau 5 — intégration adversariale

Un test qui **tente réellement une écriture** sur la connexion dédiée et vérifie
que le moteur la refuse, transaction en lecture seule active.

### Harness

Le harness multi-provider de Dalfred doit tourner avant tout tag : le bridge MCP
fait partie du périmètre qu'il couvre, conformément au `CLAUDE.md` du module.

## 14. Packaging et release

Le package MCP est un repo git indépendant, gitignoré par dalfred, embarqué au
build par `make build-release` de chaque module. L'ordre est donc : commit et push
du package sur GitHub, puis rebuild des modules qui l'embarquent.

Pour emMCP : bump `1.1.0` → `1.2.0` (fonctionnalité nouvelle, rétrocompatible),
`CHANGELOG.md`, ajout des nouveaux fichiers à `CRITICAL_FILES` du `Makefile`. Le
bump n'est pas une release : `make release-and-publish` reste à la main de Morgan.

## 15. Hors périmètre

### Branchement Dalfred — reporté volontairement

Le plan prévoyait de brancher le tool sur `DirectMcpBridge` en réutilisant les
classes d'emMCP. Ce branchement n'a **pas** été fait, et pas par manque de temps :
il imposerait à Dalfred d'inclure des fichiers d'un autre module
(`emmcp/class/emmcpsqlgateway.class.php`…), donc une dépendance croisée entre deux
produits Dolistore installables indépendamment. Un client peut très bien avoir
Dalfred sans emMCP.

Le branchement propre suppose d'abord de déplacer la gateway, l'audit et le
service de permissions dans une bibliothèque partagée — le rôle que joue déjà
`dolibarr-mcp-oauth` pour la partie OAuth. C'est un travail distinct, à mener
quand le dispositif aura tourné en production côté emMCP.

Ce qui est en revanche déjà acquis : la validation, la politique et les tools
vivent dans le runtime partagé, donc Dalfred n'aura qu'à fournir une
implémentation de `SqlCapabilityInterface` et à ajouter la classe à la liste
`getToolClasses()` — codée en dur, et où `ProjectTools` est d'ailleurs absent
depuis longtemps.

Le sort du toolkit MySQL NeuronAI de Dalfred n'est pas tranché ici. Deux politiques
read-only divergentes coexistent déjà dans ce module ; en ajouter une troisième
n'est tenable que le temps d'une transition, à traiter une fois le nouveau
dispositif éprouvé en production.

La relicence de `dolibarr-mcp-server` en GPL, qui débloquerait
`phpmyadmin/sql-parser`, est écartée : un repo public MIT a plus de valeur qu'un
parseur externe pour un besoin de validation aussi cadré, et le choix n'est
réversible que dans un sens.
