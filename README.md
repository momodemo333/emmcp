# emMCP — Serveur MCP distant pour Dolibarr

Version 1.12.0. Expose une instance Dolibarr comme **serveur MCP** (Model
Context Protocol, transport Streamable HTTP) consommable par claude.ai,
Claude Desktop, ChatGPT (connecteurs OAuth), Claude Code, l'API Anthropic
(`mcp_servers`) et tout client MCP compatible HTTP.

emMCP est un module **autonome** : il embarque son propre moteur MCP et ses
dépendances, isolées (voir plus bas). Aucun autre module E-dem n'est requis.

## Prérequis

- Dolibarr 17.0 ou plus récent ; PHP 8.1 ou plus récent.
- Module **API REST** de Dolibarr activé (les outils passent par lui).
- Accès en **HTTPS** (obligatoire pour les connecteurs OAuth).
- MySQL ou MariaDB pour l'option de requêtes SQL en lecture seule (les autres
  outils fonctionnent quelle que soit la base).

## Installation

1. Téléchargez le ZIP (DoliStore ou https://www.e-dem.com) et décompressez-le
   dans `htdocs/custom/` : le dossier final est `htdocs/custom/emmcp/`. Le ZIP
   contient déjà toutes les dépendances (`vendor/`) : rien d'autre à installer.
2. Activez **emMCP** dans *Accueil > Configuration > Modules/Applications*
   (ainsi que le module **API REST** s'il ne l'est pas).
3. Ouvrez la configuration du module : l'onglet **Paramètres** affiche l'URL
   exacte du connecteur et des exemples prêts à copier.

**Mise à jour** : décompressez la nouvelle version par-dessus l'ancienne. Les
tables du module sont migrées automatiquement à la première requête qui suit ;
inutile de désactiver/réactiver le module ou de repasser par les onglets.
Vérifiez la version et la ligne « Schéma de base » dans l'onglet **Diagnostic**.

## Onglets de configuration

- **Paramètres** : URL du serveur MCP, méthodes d'authentification, état du
  module API REST et du moteur MCP, exemples pour claude.ai, Claude Code et un
  client MCP générique (`mcp.json`).
- **Accès SQL MCP** : interrupteur global, plafonds (lignes, durée, taille),
  journalisation par empreinte seule, exception multi-société, liste des
  utilisateurs autorisés (voir plus bas).
- **Activité MCP** : journal des appels (paramètres enregistrés ou non,
  durée de conservation, purge), limite d'appels par utilisateur, seuil
  d'alerte par courriel, destinataire de l'alerte, délai entre deux alertes.
- **Accès accordés** : chaque client connecté par OAuth (utilisateur, client,
  dates d'octroi, de renouvellement et d'expiration) avec un bouton
  **Révoquer** qui coupe l'accès immédiatement.
- **Diagnostic** : vérifications, journal des connexions et rapport pour le
  support (voir plus bas).
- **À propos**.

## Fonctionnement

- Endpoint unique : `https://<instance>/custom/emmcp/mcp.php`
- **Par requête** (PHP-FPM/Apache) : pas de daemon, pas de ReactPHP.
  Chaque POST JSON-RPC est traité par le SDK PHP officiel MCP (`mcp/sdk`),
  les sessions MCP sont persistées dans `documents/emmcp/sessions/`.
- **Authentification**, deux voies :
  - **Clé API** d'un utilisateur Dolibarr, via `Authorization: Bearer <clé>`
    (recommandé) ou header `DOLAPIKEY` — pour Claude Code, l'API Anthropic,
    et tout client MCP acceptant un header custom.
  - **OAuth 2.1** (`oauth.php`) — pour les connecteurs claude.ai, Claude Desktop et ChatGPT
    qui n'acceptent qu'OAuth : découverte automatique (RFC 9728 + RFC 8414),
    enregistrement dynamique du client (RFC 7591), code d'autorisation avec
    PKCE S256, rotation des refresh tokens. L'utilisateur se connecte avec
    son compte Dolibarr et consent ; aucune clé à copier.
  Dans les deux cas, les tools agissent via l'API REST Dolibarr avec les
  permissions de l'utilisateur.
- **Tools** : ceux du moteur
  [dolibarr-mcp-server](https://github.com/momodemo333/dolibarr-mcp-server)
  embarqué — CRUD générique (`dolibarr_list`, `dolibarr_get`,
  `dolibarr_create`, `dolibarr_create_from`, `dolibarr_update`,
  `dolibarr_delete`), lignes de documents, temps passé
  (`dolibarr_add_time_spent`, `dolibarr_update_time_spent`), actions métier,
  contacts, extrafields, documents et génération PDF, création de fichiers,
  informations sur l'instance (`dolibarr_environment`) et explorateur d'API —,
  plus deux tools de **requêtes SQL en lecture seule** désactivés par défaut
  (voir ci-dessous) et ceux qu'ajoutent d'autres modules (voir *Extensions*).

## Requêtes SQL en lecture seule (optionnel, désactivé par défaut)

Deux tools supplémentaires — `dolibarr_sql_schema` et `dolibarr_sql_query` —
permettent à l'agent d'introspecter le schéma et d'exécuter des requêtes de
reporting (`SELECT`, `WITH`/CTE, `JOIN`, sous-requêtes, agrégats, `UNION`) sur
les tables métier, y compris celles des modules tiers.

Contrairement aux autres tools, qui passent par l'API REST et héritent donc des
permissions Dolibarr de l'appelant, une requête SQL n'hérite de rien. L'accès
est donc reconstruit explicitement et **quatre conditions doivent toutes être
réunies** :

1. la fonctionnalité est activée globalement (onglet **Accès SQL MCP** de la
   configuration du module) ;
2. l'utilisateur détient le droit Dolibarr *Exécuter des requêtes SQL en lecture
   seule via MCP* — **être administrateur ne suffit pas**, le droit doit être
   attribué ;
3. l'utilisateur est explicitement coché dans la liste de l'onglet Accès SQL MCP ;
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
`SELECT *` accepté seulement si aucune des colonnes qu'il recouvre n'est
sensible (sinon refusé en nommant la colonne), requêtes
limitées à la base courante (`autre_base.llx_societe` refusé), commentaires
exécutables et optimizer hints refusés, tables d'authentification et de session
inaccessibles, exécution sur une connexion dédiée en transaction de lecture
seule avec un `sql_mode` normalisé et un timeout obligatoire, plafonds de lignes
et de taille imposés par le serveur, et journal d'audit
(`llx_emmcp_sql_audit`) qui n'enregistre **jamais** les résultats.

Le tool d'introspection du schéma permet à l'agent de choisir les colonnes
dont il a besoin.

### Connexion utilisée

Aucun compte MySQL supplémentaire n'est à créer (depuis la 1.3.0) : les
requêtes utilisent les identifiants de Dolibarr, sur une session mysqli
séparée, de sorte que le timeout, le `sql_mode` et la transaction en lecture
seule ne débordent jamais sur la connexion de l'application. Une transaction
en lecture seule n'empêche pas le DDL : la lecture seule est donc garantie par
le logiciel (analyse lexicale, analyseur SQL avec liste blanche de clauses,
politique sur chaque table, colonne et fonction, contrôle final que
l'instruction commence par `SELECT` ou `WITH`).

## Exemple : Claude Code (clé API)

```bash
claude mcp add dolibarr --transport http \
  https://<instance>/custom/emmcp/mcp.php \
  --header "Authorization: Bearer VOTRE_CLE_API"
```

## Exemple : connecteur claude.ai / Claude Desktop (OAuth)

Dans claude.ai : Paramètres → Connecteurs → « Ajouter un connecteur
personnalisé », URL `https://<instance>/custom/emmcp/mcp.php`. Claude
découvre l'OAuth, ouvre la page de connexion Dolibarr et l'écran de
consentement, puis se connecte — sans clé à saisir.

Les connecteurs ajoutés dans claude.ai sont aussi disponibles dans Claude
Desktop. Pour retirer un accès donné par OAuth : onglet **Accès accordés**,
bouton **Révoquer** ; supprimer la clé API REST de l'utilisateur ou désactiver
son compte met aussi fin à tous les accès OAuth qu'il a donnés, et seule la
désactivation du compte l'empêche de se reconnecter.

## Import de relevés EMPayment (depuis la 1.12.0)

Si le module **EMPayment 2.7.0** ou plus récent est activé, emMCP ajoute deux
outils : on donne à l'IA le relevé d'une carte de crédit ou d'un compte (PDF,
CSV, photo), elle le lit et crée les mouvements dans EMPayment ; on les
rapproche ensuite dans *Banques → CODA → Classer / rapprocher*. Rien n'est
rapproché ni enregistré par ces outils.

- `empayment_review_statement` (lecture seule) : l'aperçu — lignes nouvelles,
  doublons, doublons probables, contrôle de solde. L'IA vous le montre.
- `empayment_import_statement` (écriture) : l'import, avec l'empreinte de
  l'aperçu, seulement après votre accord. Laissez votre client IA vous demander
  l'autorisation pour cet outil-là : c'est votre garde-fou.
- Le compte bancaire cible est celui que vous désignez ; l'IA ne le devine pas.
- Montants et soldes signés du point de vue du compte : sur une carte, un
  achat est négatif et un solde dû aussi, même si le relevé l'imprime positif.
- Le n° de transaction n'est recopié que s'il s'agit de l'identifiant unique de
  l'opération chez l'émetteur (jamais un n° de ligne ni un compteur).
- Renvoyer un relevé lu à l'identique ne crée rien. Un « doublon probable » (même
  jour, même montant, texte lu autrement) est presque toujours un vrai doublon :
  l'IA ne l'importe qu'après votre accord explicite, ligne par ligne.
- Droits : EMPayment « Consulter les transactions CODA » et Banques « Lire »
  pour l'aperçu ; en plus « Importer des fichiers CODA » et Banques
  « Créer/modifier » pour l'import. Les outils n'apparaissent pas sans les
  droits d'aperçu.

## Multicompany (depuis la 1.11.0)

emMCP fonctionne avec le module Multicompany, y compris en mode transverse
(utilisateurs créés dans l'entité maîtresse et partagés avec d'autres entités).
Chaque connecteur IA travaille dans **une** entité : toutes ses lectures et
écritures s'y font, avec les droits que l'utilisateur possède dans cette entité.

1. Activer emMCP **dans chaque entité** qui doit être accessible à l'IA
   (Configuration → Modules, depuis cette entité), ainsi que le module API REST.
2. Dans Multicompany, donner à l'utilisateur l'accès à l'entité (groupe
   d'utilisateurs de l'entité) avec les droits voulus. Pour lister les tiers,
   prévoir aussi « Étendre l'accès à tous les tiers ».
3. Depuis l'entité, onglet **Réglages** : copier l'**URL du connecteur pour
   l'entité** (`https://<instance>/custom/emmcp/mcp.php?entity=N`) et créer le
   connecteur avec cette URL — un connecteur par entité.
4. À la connexion, l'écran d'autorisation affiche l'entité connectée. Avec
   l'URL sans `?entity=`, l'utilisateur choisit l'entité dans une liste (celles
   auxquelles il a accès et où emMCP est activé).

Règles :

- L'entité choisie est liée à l'accès accordé (jetons OAuth) ; une demande vers
  une autre entité avec ce jeton est refusée (403).
- Avec une clé API (Claude Code, `mcp.json`) : `mcp.php?entity=N`, sinon
  l'entité principale de l'utilisateur.
- Accès vérifié à chaque requête, avec la règle de Multicompany : superadmin
  partout, sinon appartenance à un groupe de l'entité (mode transverse) ou à
  l'entité elle-même ; entité active. Refus clair sinon, jamais de repli
  silencieux sur une autre entité.
- Les **accès accordés**, le **journal d'activité** et le **journal de
  connexion** sont rangés par entité : chaque entité voit les siens (un
  superadmin change d'entité pour voir ceux d'une autre). Les réglages
  (limites, etc.) sont des constantes Dolibarr, donc propres à chaque entité.
- L'onglet **Diagnostic** affiche une ligne Multicompany : mode, entité
  courante, emMCP activé ou non dans cette entité, entités que l'utilisateur
  courant peut connecter, URL du connecteur de l'entité.
- L'accès SQL en lecture seule reste désactivé sous Multicompany : une requête
  SQL lit les lignes de toutes les entités.

## ChatGPT (connecteur personnalisé, OAuth)

Dans ChatGPT (mode développeur → créer un connecteur / une app, authentification
OAuth, champs Client ID et Client secret vides), l'URL est la même :
`https://<instance>/custom/emmcp/mcp.php`. Aucune règle de serveur web n'est
nécessaire.

Testé de bout en bout le 16/11/2026 (emMCP 1.7.0, sans aucune règle à la racine
du domaine). Le journal d'accès du serveur montre la séquence de ChatGPT :

1. `POST mcp.php` → 401, avec l'en-tête `WWW-Authenticate` ;
2. ChatGPT **suit cet en-tête** : `GET oauth.php/.well-known/oauth-protected-resource` → 200 ;
3. il essaie d'abord la racine du domaine
   (`/.well-known/oauth-authorization-server/custom/emmcp/oauth.php` → 404), puis se
   replie de lui-même sur les adresses du module
   (`oauth.php/.well-known/oauth-authorization-server` et `…/openid-configuration` → 200) ;
4. `POST oauth.php/register` (enregistrement dynamique) → 201, connexion Dolibarr et
   consentement, `POST oauth.php/token` → 200, puis appels `mcp.php` → 200.

Les 404 à la racine du domaine sont donc normaux et sans effet.

### Dépannage : « Impossible de détecter les paramètres OAuth »

Les requêtes de découverte de ChatGPT (étapes 1 à 3) portent le User-Agent
`Python/3.14 aiohttp/…`. Certains pare-feu d'hébergement (règles ModSecurity) le
refusent avec un 403 ou un 406, alors que le navigateur, curl et les étapes
suivantes de ChatGPT passent. ChatGPT affiche alors « Impossible de détecter les
paramètres OAuth », bien que le module réponde correctement.

Test, depuis n'importe quelle machine :

```bash
curl -A "aiohttp/3.14" -i https://<instance>/custom/emmcp/oauth.php/.well-known/oauth-protected-resource
```

La réponse doit être `200` avec un JSON qui contient `"resource"`, pas `403` ni
`406`. Si elle est refusée, demandez à l'hébergeur de ne plus bloquer ce
User-Agent pour le site (ou désactivez la règle dans le pare-feu applicatif de
Plesk si vous gérez le serveur). Le bouton « Vérifier l'en-tête Authorization et
la découverte OAuth » de l'onglet **Diagnostic** fait le même test depuis le
serveur lui-même : un refus y est une preuve ; un succès est un bon indice, car
un pare-feu placé en amont, ou qui exempte les adresses locales, peut traiter
autrement les requêtes venues d'OpenAI.

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

## Diagnostic (connexion qui échoue)

Onglet **Diagnostic** de la configuration du module : vérifications (module
API REST, schéma, moteur MCP et isolation de ses dépendances, conflits de
bibliothèques, dernière erreur du moteur, URL HTTPS, accès OAuth sans clé API,
en-tête Authorization), **journal des connexions** (chaque étape OAuth et
chaque requête MCP refusée, avec la raison précise, 30 jours :
`llx_emmcp_auth_log`) et un **rapport à copier pour le support**, sans aucun
secret.

## Isolation des dépendances

Dolibarr charge tous les modules dans le même processus PHP : une copie de
psr/log, guzzle ou symfony livrée par un autre module (ou par le cœur, sous
`includes/`) prendrait la place de la nôtre, et une version majeure différente
provoque une erreur fatale à chaque appel MCP. Le ZIP publié préfixe donc
toutes les dépendances tierces du moteur sous `EmmcpVendor\` (php-scoper, voir
`tools/scoper.inc.php`, étape 4b de `make build-release`, contrôlée par
`tests/runtime_isolation_test.php`). Restent partagés, volontairement :
`DolibarrMcp\` et `Mcp\`, que les modules d'extension utilisent. Pour ces deux
espaces de noms (et pour un moteur non préfixé), `lib/emmcp_runtime.php`
vérifie avant usage que chaque type clé vient bien du moteur et répond sinon
par une erreur JSON qui nomme le fichier fautif.

## Limites connues

- Découverte des tools re-scannée à chaque requête (pas de cache PSR-16).
- Multicompany : l'accès SQL en lecture seule reste refusé (voir la section
  Multicompany) ; les clients OAuth enregistrés sont comptés dans l'entité 1.
- Portée OAuth unique (`dolibarr`) : pas encore de granularité par scope.
- Les requêtes SQL en lecture seule nécessitent MySQL ou MariaDB (PostgreSQL
  n'est pas pris en charge par cette fonctionnalité).

## Extensions

Depuis la 1.8.0, un autre module Dolibarr peut ajouter ses propres outils MCP sans
modifier emMCP : il déclare le contexte de hook `emmcp` et implémente
`emmcpRegisterTools($parameters, &$object, &$action, $hookmanager)`. Il y ajoute à
`$parameters['collector']` (un `ArrayObject`) un objet `\DolibarrMcp\Extension\ToolExtension`.
`$parameters['user']` est l'utilisateur de la session MCP, droits chargés : les
outils de l'extension s'exécutent dans Dolibarr en son nom et doivent vérifier ses droits.
Exemple : le module emMCP Extensions (outils Todo).

## Dépannage

- **Point de départ : l'onglet Diagnostic.** Une ligne en alerte et la raison
  indiquée dans le journal des connexions désignent en général la cause.
- **Après une mise à jour par décompression depuis une version 1.10.1 ou
  antérieure, rien n'a changé** (Diagnostic affiche l'ancienne version,
  comportement incohérent) : le cache d'opcodes de PHP (opcache) peut encore
  servir l'ancien code. Redémarrez PHP (PHP-FPM, ou Apache si PHP tourne en
  module), ou demandez-le à votre hébergeur. Corrigé à partir de la 1.10.2 :
  chaque version porte désormais ses propres dates de fichiers.
- **Tables du module** : elles sont migrées automatiquement à la première
  requête qui suit une mise à jour ; il n'est pas nécessaire de repasser par
  les onglets. La ligne « Schéma de base » du Diagnostic doit être « OK ».
- **ChatGPT** : voir *Dépannage : « Impossible de détecter les paramètres
  OAuth »* plus haut.
- **Support** : support@e-dem.com — https://www.e-dem.com. Joignez le
  *Rapport pour le support* de l'onglet Diagnostic (il ne contient aucun
  secret).
