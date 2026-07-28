# Tool MCP « SQL lecture seule » — plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Exposer deux tools MCP (`dolibarr_sql_schema`, `dolibarr_sql_query`) permettant à un agent MCP distant d'introspecter le schéma et d'exécuter des requêtes de reporting en lecture seule, désactivés par défaut et soumis à un droit Dolibarr explicite par utilisateur.

**Architecture:** La validation SQL (lexer fail-closed + analyse AST) vit dans le runtime partagé `dolibarr-mcp-server` (MIT, sans dépendance Dolibarr). L'exécution, l'autorisation et l'audit vivent dans le module hôte `emmcp`, qui injecte une *capacité* dans le container du runtime. Sans capacité injectée, les tools disparaissent de `tools/list`.

**Tech Stack:** PHP 8.1, `mcp/sdk ^0.6`, `greenlion/php-sql-parser ^4.7` (BSD-3), PHPUnit 10.5, Dolibarr 16→21, MariaDB/MySQL.

**Spec:** `docs/superpowers/specs/2026-07-28-mcp-readonly-sql-design.md`

> **Statut : exécuté, puis durci avant livraison.** Une revue de sécurité menée
> sur l'implémentation a trouvé neuf contournements de validation, corrigés
> dans la même livraison (rien n'avait été tagué ni publié). Les règles
> décrites plus bas reflètent la **rédaction initiale du plan** ; c'est la spec
> qui fait foi. Les écarts : `SELECT *` est refusé partout et non plus
> seulement sur des tables réputées sensibles ; la qualification par base de
> données est refusée ; les colonnes secrètes sont détectées par motifs en plus
> des noms exacts ; les lectures verrouillantes, les optimizer hints et les
> commentaires `/*M!` sont refusés ; multicompany bloque quelle que soit
> l'entité ; le timeout de session et la normalisation du `sql_mode` sont
> obligatoires sous peine de refus.

## Global Constraints

- PHP 8.1 minimum (`platform.php = 8.1.32` dans le package). Aucune syntaxe 8.2+.
- Dolibarr 16.x → 21.x. Jamais de `DOL_URL_ROOT.'/custom/emmcp/...'` en dur : `dol_buildpath('/emmcp/...', 0|1)`.
- `dolibarr-mcp-server` reste **MIT** et **sans dépendance NeuronAI ni Dolibarr**. Toute nouvelle dépendance doit être permissive (MIT/BSD/Apache) et compatible PHP 8.1.
- Tous les retours de tools : `string` contenant du JSON encodé avec `JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`.
- Codes d'erreur stables en `SCREAMING_SNAKE`, listés en section 10 de la spec.
- Aucun credential dans le code, les logs ou les commits.
- Migrations SQL idempotentes (`IF NOT EXISTS`).
- TDD strict : le test rouge est écrit et **exécuté en échec** avant toute ligne d'implémentation.
- Les deux repos sont distincts : `dolibarr-mcp-server` (GitHub `momodemo333/dolibarr-mcp-server`) et `emmcp` (GitHub `momodemo333/emmcp`). Commits séparés, jamais mélangés.

---

## Structure des fichiers

### `dolibarr-mcp-server` (repo MIT)

| Fichier | Responsabilité |
|---|---|
| `src/Sql/SqlLexer.php` | Tokenisation fail-closed du texte brut. Détecte multi-statement, `/*!`, commentaires non terminés, encodage invalide. Ne connaît rien à la sémantique SQL. |
| `src/Sql/SqlPolicy.php` | Objet immuable : denylists tables/colonnes/fonctions, tables sensibles, préfixe DB, limites. Aucune logique de parsing. |
| `src/Sql/SqlReadOnlyValidator.php` | Orchestre lexer → parser → whitelist de sections → politique. Produit un `SqlValidationResult`. |
| `src/Sql/SqlValidationException.php` | Exception portant un code stable. |
| `src/Sql/SqlCapabilityInterface.php` | Contrat implémenté par l'hôte : `describeSchema()`, `runSelect()`, `getPolicy()`. |
| `src/Sql/SqlExecutionResult.php` | DTO de résultat : colonnes, lignes, compteurs, troncature. |
| `src/Tools/Gated/SqlTools.php` | Les deux tools MCP. Mince : délègue validation et exécution. |
| `src/Bootstrap.php` | *(modifié)* capacité optionnelle + exclusion conditionnelle du dossier `Gated`. |

### `emmcp` (repo GPL-3.0+)

| Fichier | Responsabilité |
|---|---|
| `class/emmcpsqlpermissions.class.php` | Les 4 conditions d'autorisation. Lecture seule de la config. |
| `class/emmcpsqlgateway.class.php` | Connexion dédiée, `START TRANSACTION READ ONLY`, timeout, exécution, troncature. |
| `class/emmcpsqlaudit.class.php` | Écriture du journal sur la connexion principale. |
| `class/emmcpsqlcapability.class.php` | Implémente `SqlCapabilityInterface` : colle permissions + gateway + audit. |
| `class/emmcpmigrationhelper.class.php` | Portage du helper dalfred, classe plate. |
| `class/emmcpmigrations.class.php` | Migrations versionnées + `MODULE_VERSION`. |
| `admin/sql_access.php` | Page admin : toggle global, limites, opt-in par user, avertissement. |
| `sql/llx_emmcp_sql_audit.sql` + `.key.sql` | Journal. |
| `sql/llx_emmcp_sql_permissions.sql` + `.key.sql` | Opt-in par utilisateur. |
| `mcp.php` | *(modifié)* résolution user + construction conditionnelle de la capacité. |
| `core/modules/modEmmcp.class.php` | *(modifié)* droits, version, tables. |

---

## Task 1 : `SqlLexer` — tokenisation fail-closed

**Files:**
- Create: `src/Sql/SqlLexer.php`
- Create: `src/Sql/SqlValidationException.php`
- Test: `tests/Sql/SqlLexerTest.php`

**Interfaces:**
- Produces:
  - `SqlValidationException::__construct(string $code, string $message)`, `->getCode(): string` *(attention : `Exception::getCode()` retourne mixed ; on expose `->code()` pour un `string` typé)*
  - `SqlLexer::assertSingleReadOnlyStatement(string $sql): void` — lève `SqlValidationException` ou ne fait rien.

- [ ] **Step 1 : écrire les tests rouges**

```php
<?php
declare(strict_types=1);

namespace DolibarrMcp\Tests\Sql;

use DolibarrMcp\Sql\SqlLexer;
use DolibarrMcp\Sql\SqlValidationException;
use PHPUnit\Framework\TestCase;

class SqlLexerTest extends TestCase
{
    private SqlLexer $lexer;

    protected function setUp(): void
    {
        $this->lexer = new SqlLexer();
    }

    /** @dataProvider rejectedProvider */
    public function testRejects(string $sql, string $expectedCode): void
    {
        try {
            $this->lexer->assertSingleReadOnlyStatement($sql);
            $this->fail('Expected rejection for: ' . $sql);
        } catch (SqlValidationException $e) {
            $this->assertSame($expectedCode, $e->code(), 'wrong code for: ' . $sql);
        }
    }

    public static function rejectedProvider(): array
    {
        return [
            'multi statement'        => ["SELECT 1; DROP TABLE llx_societe", 'SQL_MULTI_STATEMENT'],
            'multi with newline'     => ["SELECT 1;\nDELETE FROM llx_societe", 'SQL_MULTI_STATEMENT'],
            'bang comment'           => ["SELECT 1 /*!32302 , (SELECT api_key FROM llx_user) */", 'SQL_EXECUTABLE_COMMENT'],
            'bang comment bare'      => ["SELECT /*! 1 */", 'SQL_EXECUTABLE_COMMENT'],
            'bang comment nested'    => ["SELECT 1 /* x /*! y */ */", 'SQL_EXECUTABLE_COMMENT'],
            'unterminated block'     => ["SELECT 1 /* never closed", 'SQL_UNTERMINATED_COMMENT'],
            'unterminated string'    => ["SELECT 'abc", 'SQL_UNTERMINATED_STRING'],
            'unterminated backtick'  => ["SELECT `abc", 'SQL_UNTERMINATED_IDENTIFIER'],
            'invalid utf8'           => ["SELECT \xC0\x80", 'SQL_INVALID_ENCODING'],
            'empty'                  => ["   ", 'SQL_EMPTY'],
            'only comment'           => ["-- just a comment", 'SQL_EMPTY'],
        ];
    }

    /** @dataProvider acceptedProvider */
    public function testAccepts(string $sql): void
    {
        $this->lexer->assertSingleReadOnlyStatement($sql);
        $this->addToAssertionCount(1);
    }

    public static function acceptedProvider(): array
    {
        return [
            'semicolon in string'      => ["SELECT nom FROM llx_societe WHERE nom = 'a;b'"],
            'trailing semicolon'       => ["SELECT 1;"],
            'trailing semicolon space' => ["SELECT 1 ;   \n  "],
            'semicolon then comment'   => ["SELECT 1; -- done"],
            'keyword in literal'       => ["SELECT nom FROM llx_societe WHERE nom = 'DROP SA'"],
            'escaped quote'            => ["SELECT nom FROM llx_societe WHERE nom = 'O\\'Brien;x'"],
            'doubled quote'            => ["SELECT nom FROM llx_societe WHERE nom = 'O''Brien;x'"],
            'backtick identifier'      => ["SELECT `create` FROM `llx_facture`"],
            'line comment hash'        => ["SELECT 1 # comment ; DROP"],
            'line comment dashes'      => ["SELECT 1 -- comment ; DROP"],
            'block comment'            => ["SELECT /* hello ; world */ 1"],
            'double quoted string'     => ['SELECT nom FROM llx_societe WHERE nom = "a;b"'],
            'hex literal'              => ["SELECT 0x414243 FROM llx_societe"],
            'cte'                      => ["WITH x AS (SELECT 1 a) SELECT a FROM x"],
        ];
    }
}
```

- [ ] **Step 2 : lancer les tests, vérifier l'échec**

Run: `cd htdocs/custom/dalfred/dolibarr-mcp-server && php8.1 vendor/bin/phpunit tests/Sql/SqlLexerTest.php`
Expected: FAIL — `Class "DolibarrMcp\Sql\SqlLexer" not found`.

- [ ] **Step 3 : implémenter `SqlValidationException`**

```php
<?php
declare(strict_types=1);

namespace DolibarrMcp\Sql;

class SqlValidationException extends \RuntimeException
{
    public function __construct(private string $stableCode, string $message)
    {
        parent::__construct($message);
    }

    public function code(): string
    {
        return $this->stableCode;
    }
}
```

- [ ] **Step 4 : implémenter `SqlLexer`**

Machine à états parcourant la chaîne caractère par caractère. États : `DEFAULT`, `SINGLE_QUOTE`, `DOUBLE_QUOTE`, `BACKTICK`, `LINE_COMMENT`, `BLOCK_COMMENT`.

Règles, dans cet ordre :
1. `mb_check_encoding($sql, 'UTF-8')` faux ⇒ `SQL_INVALID_ENCODING`.
2. Longueur > `$maxLength` (8000 par défaut, injecté au constructeur) ⇒ `SQL_TOO_LONG`.
3. Parcours : en `DEFAULT`, `/*` suivi de `!` ⇒ `SQL_EXECUTABLE_COMMENT` (vérifier aussi **à l'intérieur** d'un bloc commentaire, car `/* x /*! y */` doit être rejeté).
4. `;` en `DEFAULT` : mémoriser la position ; si du contenu significatif (hors espaces et hors commentaires) suit ⇒ `SQL_MULTI_STATEMENT`.
5. Fin de chaîne avec état ≠ `DEFAULT`/`LINE_COMMENT` ⇒ `SQL_UNTERMINATED_*` selon l'état.
6. Aucun contenu significatif ⇒ `SQL_EMPTY`.

Échappements : en `SINGLE_QUOTE`/`DOUBLE_QUOTE`, un `\` échappe le caractère suivant, et un quote doublé (`''`) reste dans l'état. En `BACKTICK`, seul le doublage (` `` `) échappe.

- [ ] **Step 5 : relancer les tests**

Run: `php8.1 vendor/bin/phpunit tests/Sql/SqlLexerTest.php`
Expected: PASS (26 cas).

- [ ] **Step 6 : commit**

```bash
git add src/Sql/SqlLexer.php src/Sql/SqlValidationException.php tests/Sql/SqlLexerTest.php
git commit -m "feat(sql): fail-closed SQL lexer for read-only validation"
```

---

## Task 2 : `SqlPolicy` — denylists et limites

**Files:**
- Create: `src/Sql/SqlPolicy.php`
- Test: `tests/Sql/SqlPolicyTest.php`

**Interfaces:**
- Produces:
  - `SqlPolicy::__construct(string $tablePrefix = 'llx_', array $overrides = [])`
  - `->isTableAllowed(string $table): bool`
  - `->isColumnAllowed(string $column): bool`
  - `->isFunctionAllowed(string $fn): bool`
  - `->isSensitiveTable(string $table): bool`
  - `->maxRows(): int`, `->timeoutSeconds(): int`, `->maxBytes(): int`, `->maxSqlLength(): int`
  - `->deniedTables(): array`, `->deniedColumns(): array` *(pour la page admin)*

Comparaisons **insensibles à la casse** (normalisation en minuscules), et un nom de table qualifié (`gsedem.llx_user`) doit être réduit à son segment terminal **avant** l'appel.

- [ ] **Step 1 : écrire les tests rouges**

```php
public function testDeniedTables(): void
{
    $p = new SqlPolicy();
    $this->assertFalse($p->isTableAllowed('llx_const'));
    $this->assertFalse($p->isTableAllowed('LLX_CONST'));
    $this->assertFalse($p->isTableAllowed('llx_session'));
    $this->assertFalse($p->isTableAllowed('llx_oauth_token'));
    $this->assertFalse($p->isTableAllowed('llx_emmcp_oauth_token'));
    $this->assertFalse($p->isTableAllowed('llx_emmcp_oauth_client'));
    $this->assertFalse($p->isTableAllowed('llx_emmcp_sql_audit'));
    $this->assertFalse($p->isTableAllowed('llx_dalfred_activity_log'));
    $this->assertFalse($p->isTableAllowed('information_schema'));
    $this->assertFalse($p->isTableAllowed('mysql'));
}

public function testAllowedTablesRequirePrefix(): void
{
    $p = new SqlPolicy();
    $this->assertTrue($p->isTableAllowed('llx_societe'));
    $this->assertTrue($p->isTableAllowed('llx_user'));          // autorisée, colonnes filtrées
    $this->assertTrue($p->isTableAllowed('llx_mymodule_stuff')); // module tiers
    $this->assertFalse($p->isTableAllowed('societe'));           // sans préfixe
}

public function testCustomPrefix(): void
{
    $p = new SqlPolicy('dolib_');
    $this->assertTrue($p->isTableAllowed('dolib_societe'));
    $this->assertFalse($p->isTableAllowed('llx_societe'));
    $this->assertFalse($p->isTableAllowed('dolib_const'));       // denylist suit le préfixe
}

public function testDeniedColumns(): void
{
    $p = new SqlPolicy();
    foreach (['pass', 'pass_crypted', 'pass_temp', 'api_key', 'token', 'token_hash',
              'client_secret', 'client_secret_hash', 'code_challenge', 'refresh_token',
              'secret', 'private_key', 'signature_key'] as $c) {
        $this->assertFalse($p->isColumnAllowed($c), $c);
        $this->assertFalse($p->isColumnAllowed(strtoupper($c)), $c);
    }
    $this->assertTrue($p->isColumnAllowed('rowid'));
    $this->assertTrue($p->isColumnAllowed('login'));
    $this->assertTrue($p->isColumnAllowed('total_ht'));
}

public function testDeniedFunctions(): void
{
    $p = new SqlPolicy();
    foreach (['sleep', 'benchmark', 'get_lock', 'release_lock', 'is_free_lock',
              'is_used_lock', 'master_pos_wait', 'source_pos_wait', 'load_file',
              'sys_exec', 'sys_eval'] as $f) {
        $this->assertFalse($p->isFunctionAllowed($f), $f);
        $this->assertFalse($p->isFunctionAllowed(strtoupper($f)), $f);
    }
    $this->assertTrue($p->isFunctionAllowed('sum'));
    $this->assertTrue($p->isFunctionAllowed('count'));
    $this->assertTrue($p->isFunctionAllowed('date_format'));
}

public function testSensitiveTables(): void
{
    $p = new SqlPolicy();
    $this->assertTrue($p->isSensitiveTable('llx_user'));
    $this->assertTrue($p->isSensitiveTable('llx_socpeople'));
    $this->assertFalse($p->isSensitiveTable('llx_facture'));
}

public function testLimitsAreOverridable(): void
{
    $p = new SqlPolicy('llx_', ['maxRows' => 50, 'timeoutSeconds' => 3]);
    $this->assertSame(50, $p->maxRows());
    $this->assertSame(3, $p->timeoutSeconds());
    $this->assertSame(200, (new SqlPolicy())->maxRows());
}

public function testLimitsAreClampedToHardCeilings(): void
{
    $p = new SqlPolicy('llx_', ['maxRows' => 999999, 'timeoutSeconds' => 600]);
    $this->assertSame(5000, $p->maxRows());
    $this->assertSame(30, $p->timeoutSeconds());
}
```

- [ ] **Step 2 : lancer, vérifier l'échec** — `php8.1 vendor/bin/phpunit tests/Sql/SqlPolicyTest.php` ⇒ classe absente.
- [ ] **Step 3 : implémenter `SqlPolicy`** — constantes de classe pour les listes, normalisation `strtolower(trim())`, plafonds durs appliqués dans le constructeur (`min()`).
- [ ] **Step 4 : relancer** ⇒ PASS.
- [ ] **Step 5 : commit** — `feat(sql): table/column/function policy with hard-capped limits`

---

## Task 3 : `SqlReadOnlyValidator` — analyse AST

**Files:**
- Create: `src/Sql/SqlReadOnlyValidator.php`
- Modify: `composer.json` (ajout `greenlion/php-sql-parser: ^4.7`)
- Test: `tests/Sql/SqlReadOnlyValidatorTest.php`

**Interfaces:**
- Consumes: `SqlLexer::assertSingleReadOnlyStatement()`, `SqlPolicy`
- Produces: `SqlReadOnlyValidator::__construct(SqlPolicy $policy, ?SqlLexer $lexer = null)`, `->validate(string $sql): string` — retourne le SQL **avec `LIMIT` appliqué**, ou lève `SqlValidationException`.

**Sections autorisées** (toute autre clé de l'arbre ⇒ `SQL_NOT_READ_ONLY`) :
`SELECT`, `FROM`, `WHERE`, `GROUP`, `HAVING`, `ORDER`, `LIMIT`, `WITH`, `UNION`, `UNION ALL`, `EXCEPT`, `INTERSECT`, `OPTIONS`, `BRACKET`.

**Extraction AST** : parcours récursif de tout tableau portant `expr_type`.
- `table` ⇒ table, sauf si le nom figure parmi les CTE collectés (`temporary-table`).
- `colref` ⇒ colonne ; segment terminal de `no_quotes.parts`, sinon `base_expr`.
- `function` / `aggregate_function` ⇒ nom via `base_expr`.
- `*` ou `alias.*` ⇒ marqueur « étoile ».

Le nom de table retenu est le **dernier** segment de `no_quotes.parts` (neutralise `gsedem.llx_user`) ; le nom de colonne également (neutralise `u.api_key`).

- [ ] **Step 1 : écrire les tests rouges**

```php
/** @dataProvider rejectedProvider */
public function testRejects(string $sql, string $code): void
{
    try {
        $this->validator->validate($sql);
        $this->fail('Expected rejection: ' . $sql);
    } catch (SqlValidationException $e) {
        $this->assertSame($code, $e->code(), $sql);
    }
}

public static function rejectedProvider(): array
{
    return [
        'update'            => ["UPDATE llx_societe SET nom='x'", 'SQL_NOT_READ_ONLY'],
        'insert'            => ["INSERT INTO llx_societe (nom) VALUES ('x')", 'SQL_NOT_READ_ONLY'],
        'delete'            => ["DELETE FROM llx_societe", 'SQL_NOT_READ_ONLY'],
        'drop'              => ["DROP TABLE llx_societe", 'SQL_NOT_READ_ONLY'],
        'create'            => ["CREATE TABLE x (a INT)", 'SQL_NOT_READ_ONLY'],
        'call'              => ["CALL myproc()", 'SQL_NOT_READ_ONLY'],
        'set'               => ["SET @x = 1", 'SQL_NOT_READ_ONLY'],
        'into outfile'      => ["SELECT rowid FROM llx_societe INTO OUTFILE '/tmp/x'", 'SQL_NOT_READ_ONLY'],
        'into dumpfile'     => ["SELECT rowid FROM llx_societe INTO DUMPFILE '/tmp/x'", 'SQL_NOT_READ_ONLY'],
        'show'              => ["SHOW TABLES", 'SQL_NOT_READ_ONLY'],

        'denied table'          => ["SELECT rowid FROM llx_const", 'SQL_FORBIDDEN_TABLE'],
        'denied table subquery' => ["SELECT nom FROM llx_societe WHERE rowid IN (SELECT rowid FROM llx_const)", 'SQL_FORBIDDEN_TABLE'],
        'denied table cte'      => ["WITH x AS (SELECT rowid r FROM llx_const) SELECT r FROM x", 'SQL_FORBIDDEN_TABLE'],
        'denied table union'    => ["SELECT rowid FROM llx_societe UNION SELECT rowid FROM llx_const", 'SQL_FORBIDDEN_TABLE'],
        'denied table backtick' => ["SELECT rowid FROM `llx_const`", 'SQL_FORBIDDEN_TABLE'],
        'denied table qualified'=> ["SELECT rowid FROM gsedem.llx_const", 'SQL_FORBIDDEN_TABLE'],
        'denied table mixedcase'=> ["SELECT rowid FROM LLX_Const", 'SQL_FORBIDDEN_TABLE'],
        'unprefixed table'      => ["SELECT rowid FROM societe", 'SQL_FORBIDDEN_TABLE'],

        'denied column'          => ["SELECT api_key FROM llx_user", 'SQL_FORBIDDEN_COLUMN'],
        'denied column aliased'  => ["SELECT u.api_key FROM llx_user u", 'SQL_FORBIDDEN_COLUMN'],
        'denied column backtick' => ["SELECT `api_key` FROM llx_user", 'SQL_FORBIDDEN_COLUMN'],
        'denied column in where' => ["SELECT rowid FROM llx_user WHERE pass_crypted = 'x'", 'SQL_FORBIDDEN_COLUMN'],
        'denied column in cte'   => ["WITH x AS (SELECT api_key k FROM llx_user) SELECT k FROM x", 'SQL_FORBIDDEN_COLUMN'],
        'denied column in order' => ["SELECT rowid FROM llx_user ORDER BY api_key", 'SQL_FORBIDDEN_COLUMN'],

        'star on sensitive'      => ["SELECT * FROM llx_user", 'SQL_STAR_ON_SENSITIVE_TABLE'],
        'star qualified'         => ["SELECT u.* FROM llx_user u", 'SQL_STAR_ON_SENSITIVE_TABLE'],
        'star join sensitive'    => ["SELECT * FROM llx_facture f JOIN llx_user u ON u.rowid = f.fk_user_author", 'SQL_STAR_ON_SENSITIVE_TABLE'],

        'sleep'      => ["SELECT SLEEP(5)", 'SQL_FORBIDDEN_FUNCTION'],
        'benchmark'  => ["SELECT BENCHMARK(1000000, MD5('x'))", 'SQL_FORBIDDEN_FUNCTION'],
        'load_file'  => ["SELECT LOAD_FILE('/etc/passwd')", 'SQL_FORBIDDEN_FUNCTION'],
        'get_lock'   => ["SELECT GET_LOCK('a', 10)", 'SQL_FORBIDDEN_FUNCTION'],
    ];
}

/** @dataProvider acceptedProvider */
public function testAccepts(string $sql): void
{
    $out = $this->validator->validate($sql);
    $this->assertStringContainsStringIgnoringCase('select', $out);
}

public static function acceptedProvider(): array
{
    return [
        'simple'        => ["SELECT rowid, nom FROM llx_societe"],
        'join'          => ["SELECT f.ref, s.nom FROM llx_facture f JOIN llx_societe s ON s.rowid = f.fk_soc"],
        'user join'     => ["SELECT u.login, COUNT(*) n FROM llx_facture f JOIN llx_user u ON u.rowid = f.fk_user_author GROUP BY u.login"],
        'cte'           => ["WITH ca AS (SELECT fk_soc, SUM(total_ht) t FROM llx_facture GROUP BY fk_soc) SELECT s.nom, ca.t FROM ca JOIN llx_societe s ON s.rowid = ca.fk_soc"],
        'cte recursive' => ["WITH RECURSIVE n AS (SELECT 1 AS x UNION ALL SELECT x+1 FROM n WHERE x < 5) SELECT x FROM n"],
        'union'         => ["SELECT rowid FROM llx_facture UNION SELECT rowid FROM llx_commande"],
        'subquery'      => ["SELECT nom FROM llx_societe WHERE rowid IN (SELECT fk_soc FROM llx_facture)"],
        'having'        => ["SELECT fk_soc, SUM(total_ht) t FROM llx_facture GROUP BY fk_soc HAVING t > 1000"],
        'star safe'     => ["SELECT * FROM llx_facture"],
        'keyword alias' => ["SELECT date_creation AS `create` FROM llx_facture"],
        'literal kw'    => ["SELECT nom FROM llx_societe WHERE nom = 'DROP SA'"],
        'third party'   => ["SELECT rowid FROM llx_mymodule_data"],
    ];
}

public function testInjectsLimitWhenAbsent(): void
{
    $out = $this->validator->validate("SELECT rowid FROM llx_societe");
    $this->assertMatchesRegularExpression('/LIMIT\s+200\s*$/i', trim($out));
}

public function testClampsExistingLimit(): void
{
    $out = $this->validator->validate("SELECT rowid FROM llx_societe LIMIT 999999");
    $this->assertMatchesRegularExpression('/LIMIT\s+200\b/i', $out);
    $this->assertStringNotContainsString('999999', $out);
}

public function testKeepsSmallerExistingLimit(): void
{
    $out = $this->validator->validate("SELECT rowid FROM llx_societe LIMIT 10");
    $this->assertMatchesRegularExpression('/LIMIT\s+10\b/i', $out);
}

public function testParseFailureIsRejected(): void
{
    $this->expectException(SqlValidationException::class);
    $this->validator->validate("SELECT FROM WHERE");
}

public function testLexerRejectionPropagates(): void
{
    try {
        $this->validator->validate("SELECT 1 /*!32302 SELECT api_key FROM llx_user */");
        $this->fail('expected rejection');
    } catch (SqlValidationException $e) {
        $this->assertSame('SQL_EXECUTABLE_COMMENT', $e->code());
    }
}
```

- [ ] **Step 2 : lancer, vérifier l'échec.**
- [ ] **Step 3 : `composer require greenlion/php-sql-parser:^4.7`** puis vérifier que `composer.json` épingle bien `^4.7` et que `composer.lock` est mis à jour.
- [ ] **Step 4 : implémenter le validateur.** Toute `\Throwable` du parser ⇒ `SqlValidationException('SQL_PARSE_ERROR', …)`. L'injection de `LIMIT` se fait par manipulation textuelle du SQL original (pas de re-sérialisation par `PHPSQLCreator`, dont la fidélité n'est pas garantie) : détecter la clause `LIMIT` finale via l'arbre, et réécrire la fin de chaîne.
- [ ] **Step 5 : relancer** ⇒ PASS (≈ 45 cas).
- [ ] **Step 6 : commit** — `feat(sql): AST-based read-only validator with table/column/function policy`

---

## Task 4 : contrat de capacité et DTO de résultat

**Files:**
- Create: `src/Sql/SqlCapabilityInterface.php`, `src/Sql/SqlExecutionResult.php`
- Test: `tests/Sql/SqlExecutionResultTest.php`

**Interfaces:**
- Produces:

```php
interface SqlCapabilityInterface
{
    /** @return array{tables: array<string, array<int, array{name: string, type: string, nullable: bool, key: string}>>, truncated: bool} */
    public function describeSchema(?string $tableFilter = null): array;

    /** @throws SqlValidationException */
    public function runSelect(string $validatedSql): SqlExecutionResult;

    public function getPolicy(): SqlPolicy;
}
```

`SqlExecutionResult` : constructeur `(array $columns, array $rows, int $rowCount, bool $truncated, int $durationMs, int $bytes)`, getters correspondants, et `toArray(): array` pour la sérialisation JSON du tool.

- [ ] **Step 1** : test rouge sur `SqlExecutionResult::toArray()` (structure, clés, `truncated`).
- [ ] **Step 2** : lancer, vérifier l'échec.
- [ ] **Step 3** : implémenter interface + DTO (readonly promoted properties, PHP 8.1 compatible).
- [ ] **Step 4** : relancer ⇒ PASS.
- [ ] **Step 5** : commit — `feat(sql): host capability contract and execution result DTO`

---

## Task 5 : les tools MCP

**Files:**
- Create: `src/Tools/Gated/SqlTools.php`
- Test: `tests/Tools/SqlToolsTest.php`

**Interfaces:**
- Consumes: `SqlCapabilityInterface`, `SqlReadOnlyValidator`, `SqlValidationException`
- Produces: `SqlTools::__construct(?SqlCapabilityInterface $capability = null)`, méthodes `#[McpTool]` `queryDatabase()` et `describeDatabaseSchema()`.

Noms MCP : `dolibarr_sql_query`, `dolibarr_sql_schema`. Les deux annotés `readOnlyHint: true`.

- [ ] **Step 1 : écrire les tests rouges**

```php
public function testQueryRefusesWithoutCapability(): void
{
    $tools = new SqlTools(null);
    $out = json_decode($tools->queryDatabase('SELECT rowid FROM llx_societe'), true);
    $this->assertFalse($out['success']);
    $this->assertSame('SQL_CAPABILITY_UNAVAILABLE', $out['code']);
}

public function testQueryReturnsRowsOnSuccess(): void
{
    $capability = $this->createMock(SqlCapabilityInterface::class);
    $capability->method('getPolicy')->willReturn(new SqlPolicy());
    $capability->expects($this->once())
        ->method('runSelect')
        ->willReturn(new SqlExecutionResult(['nom'], [['nom' => 'ACME']], 1, false, 12, 40));

    $out = json_decode((new SqlTools($capability))->queryDatabase('SELECT nom FROM llx_societe'), true);
    $this->assertTrue($out['success']);
    $this->assertSame([['nom' => 'ACME']], $out['rows']);
    $this->assertSame(1, $out['row_count']);
}

public function testQueryNeverReachesCapabilityWhenValidationFails(): void
{
    $capability = $this->createMock(SqlCapabilityInterface::class);
    $capability->method('getPolicy')->willReturn(new SqlPolicy());
    $capability->expects($this->never())->method('runSelect');

    $out = json_decode((new SqlTools($capability))->queryDatabase('DELETE FROM llx_societe'), true);
    $this->assertFalse($out['success']);
    $this->assertSame('SQL_NOT_READ_ONLY', $out['code']);
}

public function testExecutionErrorIsNotLeakedVerbatim(): void
{
    $capability = $this->createMock(SqlCapabilityInterface::class);
    $capability->method('getPolicy')->willReturn(new SqlPolicy());
    $capability->method('runSelect')
        ->willThrowException(new \RuntimeException("Access denied for user 'root'@'db' (using password: YES)"));

    $out = json_decode((new SqlTools($capability))->queryDatabase('SELECT nom FROM llx_societe'), true);
    $this->assertFalse($out['success']);
    $this->assertSame('SQL_EXECUTION_ERROR', $out['code']);
    $this->assertStringNotContainsString('password', strtolower(json_encode($out)));
    $this->assertStringNotContainsString('root', strtolower(json_encode($out)));
}

public function testSchemaRefusesWithoutCapability(): void
{
    $out = json_decode((new SqlTools(null))->describeDatabaseSchema(), true);
    $this->assertSame('SQL_CAPABILITY_UNAVAILABLE', $out['code']);
}
```

- [ ] **Step 2** : lancer, vérifier l'échec.
- [ ] **Step 3** : implémenter `SqlTools`. Chaque méthode : capacité nulle ⇒ refus ; validation ⇒ `SqlValidationException` capturée et convertie en JSON `{success:false, code, message}` ; exécution ⇒ toute `\Throwable` non-validation devient `SQL_EXECUTION_ERROR` avec message générique, le détail n'est **pas** renvoyé.
- [ ] **Step 4** : relancer ⇒ PASS.
- [ ] **Step 5** : commit — `feat(sql): dolibarr_sql_query and dolibarr_sql_schema MCP tools`

---

## Task 6 : câblage Bootstrap et exposition conditionnelle

**Files:**
- Modify: `src/Bootstrap.php:69-126` (createContainer), `src/Bootstrap.php:136-154` (buildServer), `src/Bootstrap.php:187-207` (handleHttpRequest)
- Test: `tests/Integration/StreamableHttpTransportTest.php` (ajout de deux cas)

**Interfaces:**
- Produces: `Bootstrap::createContainer(?ConnectionConfig $config = null, ?SqlCapabilityInterface $sqlCapability = null)`, `Bootstrap::buildServer(?Container, ?string, ?ConnectionConfig, ?SqlCapabilityInterface)`, `Bootstrap::handleHttpRequest(?ServerRequestInterface, ?string, ?ConnectionConfig, ?SqlCapabilityInterface)`.

Le paramètre est **ajouté en dernière position** et optionnel : les trois appelants existants (`dalfred/mcp.php`, `emmcp/mcp.php`, `public/index.php`) continuent de fonctionner sans modification.

- [ ] **Step 1 : écrire les tests rouges**

```php
public function testSqlToolIsAbsentWithoutCapability(): void
{
    $names = $this->listToolNames(null);
    $this->assertNotContains('dolibarr_sql_query', $names);
    $this->assertNotContains('dolibarr_sql_schema', $names);
}

public function testSqlToolIsPresentWithCapability(): void
{
    $capability = $this->createMock(SqlCapabilityInterface::class);
    $names = $this->listToolNames($capability);
    $this->assertContains('dolibarr_sql_query', $names);
    $this->assertContains('dolibarr_sql_schema', $names);
}
```

`listToolNames()` reprend le pattern existant du fichier : handshake `initialize`, puis `tools/list`, en passant la capacité à `handleHttpRequest`.

- [ ] **Step 2** : lancer, vérifier l'échec.
- [ ] **Step 3** : implémenter. Dans `buildServer()`, `$excludeDirs = $sqlCapability === null ? ['Gated'] : []` passé en 3ᵉ argument de `setDiscovery()`. **Vérifier empiriquement** que Symfony Finder exclut bien `src/Tools/Gated` avec cette valeur ; si le nom relatif ne suffit pas, essayer `'Tools/Gated'`, et à défaut se rabattre sur l'enregistrement manuel du tool. Le test tranche.
- [ ] **Step 4** : relancer la suite complète — `php8.1 vendor/bin/phpunit` ⇒ PASS, aucune régression sur les 20+ tools existants.
- [ ] **Step 5** : commit — `feat(sql): conditional discovery of gated SQL tools`

---

## Task 7 : documentation du package

**Files:**
- Modify: `LLM.md` (tableau de l'Overview + section `### 20.` et `### 21.` dans `Tools Reference`), `README.md`, `CHANGELOG.md`, `src/Bootstrap.php:144` (version)

- [ ] **Step 1** : ajouter les deux tools au tableau récapitulatif de `## Overview` de `LLM.md` et passer le compte de 19 à 21.
- [ ] **Step 2** : ajouter les sections `### 20. dolibarr_sql_query` et `### 21. dolibarr_sql_schema` dans `## Tools Reference`, au format maison (Purpose, tableau Parameters, `⚠️ IMPORTANT`, exemples annotés, séparateur `---`). Documenter explicitement : lecture seule, un seul statement, colonnes sensibles refusées, `SELECT *` interdit sur les tables sensibles, `LIMIT` imposé, et le fait que le tool peut être absent.
- [ ] **Step 3** : aligner la version — `setServerInfo('Dolibarr MCP Server', '2.2.0')` et entrée `## 2.2.0` dans `CHANGELOG.md`. *(Dette relevée : le code annonçait `2.0.0` alors que le CHANGELOG était en `2.1.0`.)*
- [ ] **Step 4** : rattacher la section orpheline `Resource Names` à un guide dans `src/Resources/GuideResources.php` *(dette relevée : elle n'était servie à aucun client)*.
- [ ] **Step 5** : `php8.1 vendor/bin/phpunit` ⇒ PASS, puis commit — `docs(sql): document SQL tools, align version, fix orphan guide section`

---

## Task 8 : emMCP — schéma, migrations, descripteur

**Files:**
- Create: `sql/llx_emmcp_sql_audit.sql`, `sql/llx_emmcp_sql_audit.key.sql`, `sql/llx_emmcp_sql_permissions.sql`, `sql/llx_emmcp_sql_permissions.key.sql`, `class/emmcpmigrationhelper.class.php`, `class/emmcpmigrations.class.php`
- Modify: `core/modules/modEmmcp.class.php`

**Interfaces:**
- Produces: `EmmcpMigrations::MODULE_VERSION`, `EmmcpMigrations::createHelper($db)`, `EmmcpMigrations::runIfNeeded($db)` — appelée au boot des points d'entrée.

Schéma `llx_emmcp_sql_permissions` : `rowid`, `entity`, `fk_user`, `sql_enabled TINYINT(1) DEFAULT 0 NOT NULL`, `date_creation`, `date_modification`, `fk_user_creat`, `fk_user_modif`, `UNIQUE(entity, fk_user)`, FK vers `llx_user(rowid) ON DELETE CASCADE`.

Schéma `llx_emmcp_sql_audit` : `rowid`, `entity`, `fk_user`, `date_creation`, `sql_hash CHAR(64)`, `sql_text TEXT NULL`, `duration_ms INT`, `row_count INT`, `bytes INT`, `success TINYINT(1)`, `error_code VARCHAR(64) NULL`, `source VARCHAR(16)`, index sur `fk_user`, `date_creation`, `entity`.

Droit déclaré dans le descripteur :

```php
$this->rights[$r][0] = $this->numero + 1;   // 491410
$this->rights[$r][1] = 'Exécuter des requêtes SQL en lecture seule via MCP';
$this->rights[$r][4] = 'sqlquery';
$this->rights[$r][5] = 'read';
```

- [ ] **Step 1** : écrire les fichiers SQL (idempotents, `IF NOT EXISTS`).
- [ ] **Step 2** : porter `ModuleMigrationHelper` depuis dalfred en classe plate sans namespace, adapter le préfixe de constante en `EMMCP_DB_VERSION`.
- [ ] **Step 3** : créer `EmmcpMigrations` avec `MODULE_VERSION = '1.2.0'` et la migration `1.2.0` créant les deux tables.
- [ ] **Step 4** : déclarer le droit, bumper `$this->version` à `1.2.0`, vérifier que `init()` appelle bien `_load_tables('/emmcp/sql/')` puis `EmmcpMigrations::createHelper($this->db)->forceRunAll()`.
- [ ] **Step 5** : recharger le module et vérifier en base

```bash
cd /home/morgan/project/dolibarr/doli21
php tools/module-toggle.php reload modEmmcp
# Credentials for the local dev database are in the project CLAUDE.md; they are
# deliberately not repeated here because this repository is public.
docker exec -it <db-container> mariadb -u root -p <database> \
  -e "SHOW TABLES LIKE 'llx_emmcp_sql%'; SELECT name,value FROM llx_const WHERE name='EMMCP_DB_VERSION';"
```
Expected: les deux tables existent, `EMMCP_DB_VERSION = 1.2.0`.

- [ ] **Step 6** : commit — `feat(sql): schema, migrations and Dolibarr right for read-only SQL access`

---

## Task 9 : emMCP — permissions, gateway, audit

**Files:**
- Create: `class/emmcpsqlpermissions.class.php`, `class/emmcpsqlgateway.class.php`, `class/emmcpsqlaudit.class.php`
- Test: `tests/EmmcpSqlPermissionsTest.php` (avec les fakes DoliDB de `dolibarr-mcp-oauth`)

**Interfaces:**
- Produces:
  - `EmmcpSqlPermissions::__construct($db, $conf)`, `->isGloballyEnabled(): bool`, `->hasUserOptIn(int $userId): bool`, `->canUse(User $user): bool`, `->denialCode(User $user): ?string`
  - `EmmcpSqlGateway::__construct(array $dbParams, int $timeoutSeconds, int $maxRows, int $maxBytes)`, `->runSelect(string $sql): SqlExecutionResult`, `->describeSchema(?string $filter): array`
  - `EmmcpSqlAudit::__construct($db, $conf)`, `->record(int $userId, string $sql, ?SqlExecutionResult $r, ?string $errorCode, int $durationMs, string $source): bool`

`canUse()` exige les quatre conditions : module actif, `EMMCP_SQL_ENABLED = 1`, `$user->hasRight('emmcp', 'sqlquery', 'read')`, et opt-in en base. S'y ajoute le garde-fou multi-entité : si `isModEnabled('multicompany')` et `$conf->entity != 1`, refus avec `SQL_MULTIENTITY_BLOCKED`, sauf `EMMCP_SQL_ALLOW_MULTIENTITY = 1`. `denialCode()` retourne le code stable de la **première** condition non remplie, pour un message précis à l'administrateur (et générique au modèle).

La gateway ouvre une connexion `mysqli` **dédiée** avec les paramètres Dolibarr, applique `SET SESSION max_statement_time` (MariaDB) ou `MAX_EXECUTION_TIME` (MySQL) selon `$mysqli->server_info`, puis `START TRANSACTION READ ONLY`, exécute, tronque à `maxRows`/`maxBytes`, et fait `ROLLBACK` + `close()` dans un `finally`.

- [ ] **Step 1** : test rouge sur `EmmcpSqlPermissions` — chacune des quatre conditions isolément bloquante avec le bon `denialCode()`, plus le cas multi-entité (`SQL_MULTIENTITY_BLOCKED`) et sa levée par `EMMCP_SQL_ALLOW_MULTIENTITY`.
- [ ] **Step 2** : lancer, vérifier l'échec.
- [ ] **Step 3** : implémenter les trois classes.
- [ ] **Step 4** : relancer ⇒ PASS.
- [ ] **Step 5** : test d'intégration adversarial contre la vraie base

```bash
make php s=/var/www/html/custom/emmcp/tests/integration_readonly.php
```
Le script tente `UPDATE llx_societe SET nom='x' WHERE rowid=0` **via la gateway** et doit constater le refus du moteur (transaction READ ONLY), puis vérifie qu'un `SELECT` légitime passe.

- [ ] **Step 6** : commit — `feat(sql): authorization, read-only gateway and audit trail`

---

## Task 10 : emMCP — capacité et câblage `mcp.php`

**Files:**
- Create: `class/emmcpsqlcapability.class.php`
- Modify: `mcp.php:143-159`

`mcp.php` résout le `fk_user` à partir de la clé API (requête locale sur `llx_user`, la lib OAuth ne le remontant pas), instancie un `User` Dolibarr complet avec ses droits, puis :

```php
$sqlCapability = null;
$perms = new EmmcpSqlPermissions($db, $conf);
if ($perms->canUse($mcpUser)) {
    $sqlCapability = new EmmcpSqlCapability($db, $conf, $mcpUser, 'mcp');
}
$response = DolibarrMcp\Bootstrap::handleHttpRequest(null, $sessionDir, $config, $sqlCapability);
```

- [ ] **Step 1** : écrire `EmmcpSqlCapability` implémentant `SqlCapabilityInterface`, qui compose permissions + gateway + audit, et **fait échouer la requête si l'audit échoue**.
- [ ] **Step 2** : câbler `mcp.php`, en déclenchant `EmmcpMigrations::runIfNeeded($db)` juste après le contrôle `isModEnabled`.
- [ ] **Step 3** : vérifier de bout en bout, tool absent par défaut

```bash
curl -s -X POST https://doli21.dev03.e-dem.com/custom/emmcp/mcp.php \
  -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | grep -c dolibarr_sql
```
Expected: `0` — flag global désactivé par défaut.

- [ ] **Step 4** : activer le flag et le droit, puis re-vérifier ⇒ les deux tools apparaissent, et une requête `UPDATE` est refusée avec `SQL_NOT_READ_ONLY`.
- [ ] **Step 5** : commit — `feat(sql): wire the SQL capability into the MCP endpoint`

---

## Task 11 : emMCP — page admin, i18n, packaging

**Files:**
- Create: `admin/sql_access.php`
- Modify: `lib/emmcp.lib.php` (onglet), `langs/en_US/emmcp.lang`, `langs/fr_FR/emmcp.lang`, `Makefile` (`CRITICAL_FILES`), `CHANGELOG.md`, `README.md`

La page présente le toggle global (`ajax_constantonoff`), les trois limites (lignes, timeout, octets), la liste des tables et colonnes refusées (lecture seule, issue de `SqlPolicy`), et le tableau des utilisateurs avec leur case d'opt-in.

**L'avertissement est obligatoire** et affiché en `dol_htmloutput_mesg` de type warning au-dessus du tableau des utilisateurs : accorder ce droit donne une lecture large de la base, très au-delà des permissions métier habituelles de l'utilisateur.

- [ ] **Step 1** : créer la page admin en suivant le pattern de `dalfred/admin/toolkit_permissions.php`.
- [ ] **Step 2** : ajouter l'onglet dans `emmcpAdminPrepareHead()`.
- [ ] **Step 3** : ajouter les clés de traduction FR et EN (préfixe `Emmcp`), avertissement compris.
- [ ] **Step 4** : ajouter `admin/sql_access.php`, `class/emmcpsqlcapability.class.php`, `class/emmcpsqlgateway.class.php`, `sql/llx_emmcp_sql_audit.sql` à `CRITICAL_FILES` du `Makefile`.
- [ ] **Step 5** : `make lint` ⇒ PASS, puis `make build-release` et vérification du contenu

```bash
unzip -l ../../../releases/module_emmcp-1.2.0.zip | grep -E 'sql_access|sqlgateway|sql_audit|Sql/'
```
Expected: tous présents, y compris `vendor/dolibarr-mcp-server/src/Sql/` et `vendor/.../greenlion/`.

- [ ] **Step 6** : `CHANGELOG.md` (entrée `## [1.2.0]`, sections Added/Security) et `README.md` (section fonctionnalité + note de sécurité).
- [ ] **Step 7** : commit — `feat(sql): admin page, i18n and packaging for read-only SQL access`

---

## Task 12 : Dalfred — réutilisation (non bloquant pour la release emMCP)

**Files:**
- Modify: `src/MCP/DirectMcpBridge.php` (`getToolClasses()`, construction de la capacité)

- [ ] **Step 1** : ajouter `\DolibarrMcp\Tools\Gated\SqlTools::class` **et** `\DolibarrMcp\Tools\ProjectTools::class` à `getToolClasses()` *(ce dernier était absent — dette relevée)*.
- [ ] **Step 2** : construire la capacité dans le bridge à partir du user Dalfred courant, en réutilisant les classes d'emMCP si le module est actif, sinon `null`.
- [ ] **Step 3** : `make test-harness` (tier smoke) ⇒ PASS. **Obligatoire avant tout tag**, le bridge MCP étant dans le périmètre couvert.
- [ ] **Step 4** : commit — `feat(sql): expose the read-only SQL tool to the Dalfred agent`

---

## Ordre d'exécution et dépendances

Tâches 1 → 7 dans le repo `dolibarr-mcp-server`, puis commit et push GitHub **avant** de commencer la tâche 8, car emMCP embarque le package au build.

Tâches 8 → 11 dans le repo `emmcp`. La tâche 12 est indépendante et peut être différée.

## Vérification finale

- [ ] `php8.1 vendor/bin/phpunit` dans le package ⇒ vert, y compris les 20+ tools existants.
- [ ] `make lint` dans emMCP ⇒ vert.
- [ ] Tool absent de `tools/list` avec le flag désactivé.
- [ ] Un `UPDATE` refusé au niveau validateur **et** au niveau moteur (les deux testés séparément).
- [ ] Aucun credential dans le diff : `git log -p | grep -iE 'password|api_key|secret' | grep -v '<test>'`.
- [ ] `~/dev-docs/dolibarr/db-migrations.md` enrichi du pattern « migration au boot des points d'entrée pour un module sans UI ».
