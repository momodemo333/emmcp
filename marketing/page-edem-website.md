# emMCP — Brief pour la page produit e-dem.com

Document destiné à l'agent chargé d'ajouter le produit sur le site.
Tout le contenu ci-dessous est rédigé pour être publié tel quel ou adapté.

- **URL cible** : `https://www.e-dem.com/fr/produits/emmcp`
  (et `/en/`, `/de/`, `/es/`, `/it/` — le segment `produits` reste en français,
  comme pour Dalfred)
- **Prix affiché** : 15 € HT, achat unique via le DoliStore
- **Lien d'achat** : fiche DoliStore du module (à renseigner une fois créée)
- **Module lié** : Dalfred — proposer un renvoi croisé entre les deux pages
- **Aucun numéro de version** ne doit apparaître : la page ne doit pas être
  retouchée à chaque publication.

---

## Titre et accroche

**emMCP — Votre Dolibarr, dans votre assistant IA**

> Ajoutez un connecteur, et votre assistant travaille dans votre ERP.

---

## Le problème

Les assistants IA sont devenus des outils de travail quotidiens. Mais ils ne
connaissent pas votre entreprise. Pour qu'ils vous soient utiles sur vos
affaires, il faut leur copier-coller des données, exporter un tableau, décrire
un contexte qu'ils oublieront à la conversation suivante.

Pendant ce temps, tout ce qu'ils auraient besoin de savoir est déjà dans votre
Dolibarr.

---

## La solution

emMCP expose votre Dolibarr comme **serveur MCP** — le standard ouvert qui
permet à un assistant IA de se connecter à un système externe.

Concrètement : vous ajoutez un connecteur dans claude.ai, Claude Code ou le
client de votre choix, et votre assistant peut consulter et modifier vos
données Dolibarr. Vous continuez à travailler dans l'outil que vous utilisez
déjà, il sait désormais de quoi vous parlez.

---

## Mise en route

Trois étapes, quelques minutes :

1. **Installez et activez** le module dans Dolibarr.
2. **Réglez les permissions** de l'utilisateur associé à l'assistant.
3. **Collez l'URL** du connecteur dans votre client IA.

La page de configuration affiche l'URL, la commande Claude Code et le fichier
`mcp.json` prêts à copier. Il n'y a pas d'autre paramétrage.

---

## Ce que l'assistant sait faire

Une vingtaine d'outils couvrent le quotidien :

- **Consulter** — retrouver un tiers, une facture, une commande, un projet
- **Créer et modifier** — devis, factures, commandes, produits, avec leurs lignes
- **Enchaîner** — valider un devis, le transformer en commande, générer le PDF
- **Gérer les documents** — lister, téléverser, télécharger, générer
- **Aller plus loin** — champs personnalisés, contacts liés, suivi de temps

### Exemples de demandes

> « Quelles factures de ce client sont impayées depuis plus de 30 jours ? »
> « Crée un devis pour Dupont : 10 h de conseil à 85 €, 5 h de développement à 95 € »
> « Valide le devis PR2025-0018, transforme-le en commande et génère le PDF »
> « Ajoute un contact sur ce projet et note 3 h de travail cette semaine »

---

## La sécurité, dans le bon sens

C'est le point à mettre en avant sur la page.

**L'assistant n'a aucun droit propre.** Il agit au nom d'un utilisateur
Dolibarr, avec ses permissions, module par module. Un assistant en lecture
seule, c'est simplement un utilisateur en lecture seule.

- Authentification par **OAuth 2.1** (PKCE, rotation des jetons) pour les
  connecteurs claude.ai, ou par clé API pour les autres clients
- Accès révocable à tout moment
- Les actions passent par l'API REST de Dolibarr, celle que vous connaissez

### L'accès SQL, en option

Pour les analyses que l'API ne couvre pas, un accès SQL **en lecture seule**
peut être ouvert. Il est désactivé par défaut et demande quatre conditions
réunies : interrupteur global, permission Dolibarr, validation nominative de
l'utilisateur, et déverrouillage explicite en environnement multi-sociétés.
Tant qu'il en manque une, les outils SQL n'existent pas pour le client.

Chaque requête est analysée avant d'atteindre le serveur : seules les
consultations passent, les écritures sont refusées, les colonnes sensibles sont
hors de portée, et tout est journalisé.

---

## Ce qu'il n'y a pas à faire

- Aucun service à héberger, aucun démon, aucun port à ouvrir
- Aucun conteneur à maintenir
- Aucun abonnement supplémentaire : vous gardez votre fournisseur d'IA

emMCP tourne dans votre Dolibarr comme n'importe quelle page PHP.

---

## Prérequis

- Dolibarr 16 ou supérieur, PHP 8.1 ou supérieur
- Module API REST de Dolibarr activé
- Instance accessible en HTTPS depuis le client IA
- Un compte chez le fournisseur d'IA de votre choix

---

## Renvoi vers Dalfred

Sur cette page, prévoir un encart :

> **Vous préférez un assistant intégré à Dolibarr ?**
> Dalfred ajoute un chat IA directement dans votre ERP, sans client externe.
> [Découvrir Dalfred](https://www.e-dem.com/fr/produits/dalfred)

Et sur la page Dalfred, l'encart symétrique renvoyant vers emMCP.

---

## Visuels disponibles

Dans `marketing/screenshots/` du dépôt du module :

- `emmcp_admin_setup.png` — page de configuration avec l'URL du connecteur
- `emmcp_admin_sql_access.png` — écran de l'accès SQL en lecture seule

Une image de couverture reste à produire — voir
`marketing/prompt-image-couverture.md`.

---

## Métadonnées SEO suggérées

- **Title** : emMCP — Connectez votre assistant IA à Dolibarr | E-dem
- **Description** : Exposez votre Dolibarr comme serveur MCP et pilotez votre
  ERP depuis claude.ai, Claude Code ou tout client compatible. Les permissions
  Dolibarr font foi. Installation en quelques minutes.
- **Mots-clés** : MCP Dolibarr, connecteur IA Dolibarr, Claude Dolibarr,
  serveur MCP ERP, assistant IA ERP
