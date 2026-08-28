# Captures d'écran emMCP

Capturées à 1440 px de large, page entière, sur une instance Dolibarr 21
en français.

## `emmcp_admin_setup.png`

Page de configuration du module (onglet « Paramètres »).

Montre l'URL de l'endpoint MCP, les méthodes d'authentification disponibles,
et surtout la section « Connecter un client » : l'URL du connecteur claude.ai,
la commande Claude Code et le fichier `mcp.json`, chacun avec son bouton de
copie.

**C'est la capture principale** : elle démontre l'argument de vente — la mise
en route tient en un copier-coller.

*Légende suggérée* : « La page de configuration donne l'URL du connecteur, la
commande Claude Code et le fichier mcp.json prêts à copier. »

## `emmcp_admin_sql_access.png`

Onglet « Accès SQL MCP ».

Montre l'interrupteur global, les limites configurables (lignes, durée, taille
de réponse), le tableau des utilisateurs avec les trois colonnes « Droit
Dolibarr », « Opt-in individuel » et « Accès effectif », le périmètre refusé
(tables, colonnes, fonctions) et le journal des dernières requêtes.

*Légende suggérée* : « L'accès SQL en lecture seule : désactivé par défaut,
autorisé utilisateur par utilisateur, périmètre refusé explicite et journal
des requêtes. »

## À refaire si

- L'interface Dolibarr change visiblement de thème
- La page de configuration gagne une section importante

Commande utilisée (depuis `/home/morgan/project/sapins.be/dev/tools/playwright-cli`) :

```bash
node dolibarr-login.js https://doli21.dev03.e-dem.com morgan '<mdp>' /tmp/doli21-session.json
node shot.js https://doli21.dev03.e-dem.com/custom/emmcp/admin/setup.php \
  --storage /tmp/doli21-session.json --out emmcp_admin_setup.png \
  --width 1440 --height 900 --full
```
