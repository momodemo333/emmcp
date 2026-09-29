# Captures d'écran emMCP

Capturées à 1440 px de large, sur une instance Dolibarr 21 en français.
Les captures de 2026-09-28 ont été prises en 2880 px (deviceScaleFactor 2)
puis réduites à 1440 px, ce qui donne un rendu de texte plus net.

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


## `emmcp_activity_log.png`

**La capture la plus parlante pour le DoliStore.** Cadrage sur le tableau des
appels réellement reçus : date, utilisateur, méthode, outil appelé, durée,
état, et les paramètres de chaque appel.

Produite en passant de vrais appels MCP sur l'instance (initialize, tools/list,
puis dolibarr_list sur factures / tiers / produits, dolibarr_get, et
dolibarr_environment), depuis un client déclaré « claude-ai ».

C'est la seule capture qui montre le module **en train de fonctionner** plutôt
que configuré. C'est celle qui répond à la demande du DoliStore : « descriptive
images illustrating the module's functionality ».

*Légende suggérée* : « Chaque appel de l'agent est tracé : qui, quel outil,
avec quels paramètres, en combien de temps. »

## `emmcp_activity_full.png`

La même page en entier : les réglages de journalisation (rétention, limite
d'appels par utilisateur, seuil d'alerte par courriel) au-dessus du tableau.

*Légende suggérée* : « Journalisation, quota d'appels par utilisateur et alerte
par courriel : l'administrateur garde la main. »

## `emmcp_oauth_consent.png`

L'écran de consentement OAuth 2.1, tel que le voit l'utilisateur quand
claude.ai demande l'accès. Nomme l'application, le compte Dolibarr concerné, et
dit explicitement que l'agent n'aura pas plus de droits que l'utilisateur.

Corrigé en 1.5.1 : la carte est centrée, le titre ne porte plus le contour
de focus que Dolibarr dessine sur son `h1`, et les deux boutons sont lisibles.
Aucune retouche sur la capture livrée.

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
