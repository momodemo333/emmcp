# Journal des modifications

Versions antérieures à 1.11.0 : voir `CHANGELOG.md` (en anglais).

## [1.12.0] - 2026-11-20

### Ajouté
- **Nouvel outil `empayment_import_statement` : donnez à l'IA le relevé de
  votre carte de crédit ou de votre compte (PDF, CSV, photo), elle crée les
  mouvements dans EMPayment**, à rapprocher ensuite dans les écrans CODA
  d'EMPayment. L'outil n'est proposé que si EMPayment 2.7.0 ou plus récent est
  activé dans l'entité de la connexion et que l'utilisateur peut consulter un
  relevé (EMPayment « Consulter les transactions CODA » et Banques « Lire ») ;
  l'import demande en plus EMPayment « Importer des fichiers CODA » et Banques
  « Créer/modifier ».
  - Deux appels, comme toutes les actions d'EMPayment : un aperçu qui n'écrit
    rien (lignes nouvelles, doublons, doublons probables, contrôle de solde,
    avertissements, empreinte), puis l'import avec `confirm=true` et cette
    empreinte.
  - L'outil demande à l'IA de montrer l'aperçu avant de confirmer, de ne jamais
    deviner le compte bancaire, de signer les montants du point de vue du
    compte (achat par carte négatif), de recopier le n° de transaction quand le
    relevé en imprime un, de fournir les soldes de début et de fin, et de ne
    jamais modifier une ligne pour contourner un doublon signalé (renvoyer le
    même relevé ne crée rien).
  - Chaque refus revient complet, avec la marche à suivre : toutes les lignes
    fautives d'un relevé refusé, écart de solde, compte inconnu, aperçu périmé
    (409 : refaire l'aperçu), et jamais de second import après un 503 ou une
    réponse perdue.
  - Passe par l'API REST d'EMPayment avec la clé API et l'entité de la session
    MCP (Multicompany compris) : les droits, l'entité et les règles de doublons
    d'EMPayment s'appliquent. Derrière un proxy qui redirige http vers https,
    l'appel est refait une fois en https sur le même hôte.

### Notes
- Aucune modification de la base (version de schéma 1.12.0, rien à migrer).
  Sans EMPayment 2.7.0+, rien ne change.

## [1.11.0] - 2026-11-20

### Ajouté
- **Multicompany : l'IA peut travailler dans n'importe quelle entité à laquelle
  l'utilisateur a accès.** Entité choisie sur l'écran d'autorisation OAuth (ou
  fixée par l'URL de connecteur `mcp.php?entity=N`), liée aux jetons, accès
  vérifié à chaque requête, appels REST dans cette entité (en-tête
  `DOLAPIENTITY`). Accès accordés et journaux par entité ; ligne Multicompany
  dans le Diagnostic. L'accès SQL reste refusé sous Multicompany. Sans
  Multicompany, rien ne change.
