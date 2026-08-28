# Fiche produit DoliStore — emMCP

Tout ce qu'il faut pour remplir le formulaire de soumission. Les descriptions
longues sont dans `dolistore/description_<lang>.html`, prêtes à coller.

---

## Métadonnées

| Champ | Valeur |
|---|---|
| **Nom du produit** | emMCP — Serveur MCP pour Dolibarr |
| **Référence** | emmcp |
| **Prix** | 15,00 € HT |
| **Catégorie** | Interfaces avec systèmes externes (ou « Outils » selon l'arborescence du jour) |
| **Éditeur** | E-dem |
| **Licence** | GPL-3.0-or-later |
| **Compatibilité Dolibarr** | 16.0 → 23.x |
| **PHP** | 8.1 minimum |
| **URL éditeur** | https://www.e-dem.com/fr/produits/emmcp |
| **Support** | https://www.e-dem.com (formulaire de contact) |

### Mots-clés

`MCP`, `Model Context Protocol`, `IA`, `AI`, `assistant`, `Claude`, `claude.ai`,
`Claude Code`, `Anthropic`, `API`, `REST`, `OAuth`, `connecteur`, `connector`,
`chatbot`, `automatisation`, `SQL`, `reporting`

---

## Titres par langue

| Langue | Titre |
|---|---|
| FR | emMCP — Serveur MCP pour Dolibarr |
| EN | emMCP — MCP server for Dolibarr |
| DE | emMCP — MCP-Server für Dolibarr |
| ES | emMCP — Servidor MCP para Dolibarr |
| IT | emMCP — Server MCP per Dolibarr |

---

## Descriptions courtes (≤ 400 caractères)

Elles figurent aussi en commentaire en tête de chaque fichier HTML.

**FR** (350) — Connectez votre assistant IA directement à votre Dolibarr. emMCP expose votre ERP comme serveur MCP : ajoutez le connecteur dans claude.ai, Claude Code ou tout client compatible, et dialoguez avec vos données. Chaque action respecte les permissions Dolibarr de l'utilisateur connecté. Installation en quelques minutes, aucun service à héberger.

**EN** (371) — Connect your AI assistant straight to your Dolibarr. emMCP exposes your ERP as an MCP server: add the connector in claude.ai, Claude Code or any compatible client, and work with your data by talking to it. Every action respects the Dolibarr permissions of the linked user. Set up in minutes, with no service to host.

**DE** (377) — Verbinden Sie Ihren KI-Assistenten direkt mit Ihrem Dolibarr. emMCP stellt Ihr ERP als MCP-Server bereit: Connector in claude.ai, Claude Code oder einem beliebigen kompatiblen Client hinzufügen und im Dialog mit Ihren Daten arbeiten. Jede Aktion folgt den Dolibarr-Berechtigungen des verknüpften Benutzers. In Minuten eingerichtet, ohne eigenen Dienst.

**ES** (379) — Conecte su asistente de IA directamente a su Dolibarr. emMCP expone su ERP como servidor MCP: añada el conector en claude.ai, Claude Code o cualquier cliente compatible y trabaje con sus datos conversando. Cada acción respeta los permisos Dolibarr del usuario asociado. Se configura en minutos y no hay ningún servicio que alojar.

**IT** (376) — Collegate il vostro assistente IA direttamente al vostro Dolibarr. emMCP espone il vostro ERP come server MCP: aggiungete il connettore in claude.ai, Claude Code o in qualsiasi client compatibile e lavorate sui vostri dati dialogando. Ogni azione rispetta i permessi Dolibarr dell'utente collegato. Si configura in pochi minuti, senza servizi da ospitare.

---

## Descriptions longues

Coller le contenu **après** le bloc de commentaire de chaque fichier :

- `dolistore/description_fr.html`
- `dolistore/description_en.html`
- `dolistore/description_de.html`
- `dolistore/description_es.html`
- `dolistore/description_it.html`

Le HTML est volontairement simple (`h2`, `h3`, `p`, `ul`, `ol`, `strong`, `a`,
`hr`) pour passer sans retouche dans l'éditeur DoliStore.

---

## Captures d'écran

Dans `screenshots/`, capturées en 1440 px de large :

| Fichier | Légende suggérée |
|---|---|
| `emmcp_admin_setup.png` | La page de configuration donne l'URL du connecteur, la commande Claude Code et le fichier `mcp.json` prêts à copier. |
| `emmcp_admin_sql_access.png` | L'accès SQL en lecture seule : désactivé par défaut, permission par utilisateur, périmètre refusé et journal des requêtes. |

Ordre d'affichage conseillé : `setup` en premier — c'est celle qui montre que
la mise en route tient en un copier-coller.

---

## Notes de rédaction

- **Aucun numéro de version** n'apparaît dans les descriptions : elles n'ont pas
  à être retouchées à chaque publication.
- L'argument mis en avant est la **simplicité de mise en route** (trois étapes)
  et le fait que **les permissions Dolibarr existantes font foi** — pas une
  liste de fonctionnalités techniques.
- L'accès SQL est présenté comme **optionnel et verrouillé par défaut**, pour
  qu'il rassure au lieu d'inquiéter.
- Le module ne vend pas d'IA : le client garde son abonnement et son
  fournisseur. C'est dit explicitement dans les prérequis.
