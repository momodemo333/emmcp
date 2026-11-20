# Journal des modifications

Versions antérieures à 1.11.0 : voir `CHANGELOG.md` (en anglais).

## [1.12.0] - 2026-11-20

### Ajouté
- **Deux nouveaux outils pour donner à l'IA le relevé d'une carte de crédit ou
  d'un compte (PDF, CSV, photo) et lui faire créer les mouvements dans
  EMPayment**, à rapprocher ensuite dans les écrans CODA d'EMPayment. Proposés
  seulement si EMPayment 2.7.0 ou plus récent est activé dans l'entité de la
  connexion et que l'utilisateur peut consulter un relevé (EMPayment
  « Consulter les transactions CODA » et Banques « Lire ») ; l'import demande
  en plus « Importer des fichiers CODA » et Banques « Créer/modifier ».
  - `empayment_review_statement` est **en lecture seule** : lignes nouvelles,
    doublons, doublons probables, contrôle de solde, solde Dolibarr,
    avertissements, empreinte. `empayment_import_statement` écrit, exige
    l'empreinte de l'aperçu et ne s'appelle qu'après l'accord de l'utilisateur
    sur cet aperçu. Deux outils plutôt qu'un seul avec un drapeau : la demande
    d'autorisation du client pour l'outil qui écrit devient une vraie barrière
    humaine (un client peut laisser l'aperçu, en lecture seule, s'exécuter
    sans demander).
  - Paramètres du relevé communs aux deux ; schéma de ligne fermé (une clé
    inconnue comme `"type": "debit"` est refusée au lieu de transformer en
    silence un débit en crédit).
  - Consignes à l'IA, dans la description des outils : montrer l'aperçu avant
    d'importer ; ne jamais deviner le compte ; montants **et soldes** signés du
    point de vue du compte (sur une carte, un montant dû est négatif même
    imprimé positif ; en cas d'écart, essayer d'abord l'opposé des deux
    soldes, jamais l'opposé des lignes) ; `external_id` réservé à l'identifiant
    unique de la transaction chez l'émetteur, jamais un n° de ligne, un
    compteur du relevé ni un code d'autorisation ; un rejeu ne crée rien
    seulement si les lignes sont lues à l'identique, et les doublons probables
    sont presque toujours de vrais doublons : `accept_probable_duplicates`
    seulement après l'accord explicite de l'utilisateur, ligne par ligne (puis
    refaire l'aperçu avec ce champ, il fait partie de l'empreinte).
  - Chaque refus revient complet, avec la marche à suivre par code d'erreur :
    lignes fautives, `BALANCE_MISMATCH` (avec l'indice du serveur),
    `LINE_EXTERNAL_ID_CONFLICT` (retirer l'external_id, jamais changer date ou
    montant), `PROBABLE_DUPLICATES_UNCONFIRMED`, `LINE_FIELD_UNKNOWN` /
    `FIELD_UNKNOWN`, compte inconnu, aperçu périmé, et jamais de second import
    après un 503 ou une réponse perdue. Après un import qui a créé moins de
    lignes bancaires que de mouvements (`BANK_LINES_MISSING`), l'IA est invitée
    à prévenir l'utilisateur.
  - Passe par l'API REST d'EMPayment avec la clé API et l'entité de la session
    MCP (Multicompany compris). Derrière un proxy qui redirige http vers https,
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
