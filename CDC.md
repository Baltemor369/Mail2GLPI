# Cahier des Charges — GLPI Drag'n'Drop Mail → Ticket

> **Version du document** : 0.1.0 — 2026-06-23
> **Statut** : Brouillon (en cours de cadrage)
> **Cible** : GLPI 11.x auto-hébergé

---

## 1. Contexte & objectifs

### 1.1 Contexte
Les agents de support traitent une part importante de leurs demandes par e-mail. Aujourd'hui,
transformer un e-mail en ticket GLPI impose une ressaisie manuelle (copier le sujet, le corps,
identifier le demandeur, rattacher les pièces jointes), source de perte de temps et d'erreurs.

GLPI dispose d'un **collecteur de mails** (IMAP, *pull*) qui crée des tickets automatiquement,
mais ce mécanisme est asynchrone, global, et ne couvre pas le besoin d'un agent qui veut
**convertir à la demande un e-mail précis** depuis sa boîte, avec contrôle avant validation.

### 1.2 Objectif
Permettre à un agent de **convertir un e-mail en ticket GLPI en un geste** (glisser-déposer ou
clic), avec extraction automatique des informations et **pré-remplissage des champs**, l'agent
n'ayant « plus qu'à valider ».

### 1.3 Gains attendus
- Réduction du temps de création d'un ticket à partir d'un e-mail.
- Diminution des erreurs de ressaisie (demandeur, objet, pièces jointes oubliées).
- Traçabilité : l'e-mail d'origine est rattaché au ticket.

---

## 2. Périmètre

### 2.1 Inclus
- Intégration à **GLPI 11.x auto-hébergé** (installation de plugins tierce-partie requise).
- Couverture des clients : **Outlook classique (desktop)**, **Nouveau Outlook**, **Outlook Web (OWA / M365)**.
- Deux briques complémentaires : **Plugin GLPI** + **Add-in Outlook**.
- Mapping automatique : titre, description, demandeur (par e-mail), observateurs (Cc),
  pièces jointes, et affectation entité/catégorie/urgence/SLA par règles.

### 2.2 Exclus (à ce stade)
- **GLPI Cloud / SaaS Teclib** (plugins tiers restreints).
- Clients mail hors Outlook (Thunderbird, Gmail web, Zimbra…) — non prioritaires ; restent
  utilisables via le fallback « fichier `.eml` » de la Brique A mais non garantis.
- Synchronisation bidirectionnelle ticket ↔ e-mail (réponses depuis GLPI vers la boîte).
- Création/édition d'utilisateurs GLPI (le demandeur est rattaché par e-mail, pas créé).

### 2.3 Hypothèses
- L'instance GLPI expose son **API REST** (app-token configuré) accessible depuis les postes agents.
- Les agents disposent d'un **user-token** GLPI personnel (ou d'un moyen de l'obtenir).
- Le déploiement de l'add-in Outlook est possible sur le tenant M365 (droits administrateur).

---

## 3. Acteurs & cas d'usage

| Acteur | Rôle |
|---|---|
| **Agent de support** | Convertit un e-mail en ticket, vérifie le pré-remplissage, valide. |
| **Administrateur GLPI** | Installe/configure le plugin, l'app-token, les règles de mapping. |
| **Administrateur M365** | Déploie l'add-in Outlook sur le tenant. |
| **Demandeur** | Expéditeur de l'e-mail ; rattaché au ticket par son adresse. |

### Cas d'usage principaux
- **UC1** — *Depuis Outlook (tout client)* : l'agent sélectionne un mail, clique « Créer un
  ticket GLPI » (ou glisse le mail sur le volet de l'add-in) → aperçu du mapping → validation.
- **UC2** — *Depuis GLPI (fichier)* : l'agent glisse un fichier `.eml`/`.msg` dans la dropzone
  GLPI → le formulaire de création se pré-remplit → validation.
- **UC3** — *Depuis Outlook classique + Chrome/Edge* : l'agent glisse directement le mail dans
  la dropzone GLPI (best-effort, selon support navigateur).

---

## 4. Exigences fonctionnelles

### F1 — Dépôt du mail
- **F1.1** L'add-in Outlook offre un bouton/volet « Créer un ticket GLPI » dans la lecture d'un mail.
- **F1.2** L'add-in supporte le *drag d'un mail sur le volet* (fonction Office.js native).
- **F1.3** Le plugin GLPI affiche une **dropzone** acceptant les fichiers `.eml` (et `.msg` en option).
- **F1.4** Best-effort : le plugin tente d'exploiter un drag direct depuis Outlook classique
  (Chrome/Edge) ; en cas d'échec, message guidant vers l'enregistrement du fichier `.eml`.

### F2 — Extraction & mapping
- **F2.1** Extraction : expéditeur (From), destinataires (To/Cc), sujet, corps (texte + HTML), date, pièces jointes.
- **F2.2** Sujet → **titre** du ticket.
- **F2.3** Corps (HTML nettoyé / texte) → **description** du ticket.
- **F2.4** Expéditeur → **demandeur par e-mail** (rattachement par adresse, sans exiger de compte GLPI — aligné sur le collecteur natif).
- **F2.5** Adresses en **Cc** → **observateurs** (par e-mail), si activé.
- **F2.6** Le corps HTML est **assaini** (anti-XSS) avant insertion dans GLPI.

### F3 — Pré-remplissage & validation
- **F3.1** À la fin du dépôt, le **formulaire standard de création de ticket** GLPI est
  pré-rempli (titre, description, demandeur, observateurs) ; l'agent vérifie et valide.
- **F3.2** *(Brique B)* Alternative : l'add-in affiche un **aperçu du mapping** avant d'appeler l'API ; après validation, le ticket est créé via l'API REST.
- **F3.3** Aucun ticket n'est créé sans action explicite de l'agent.

### F4 — Règles de mapping (entité / catégorie / urgence / SLA)
- **F4.1** Affectation de l'**entité**, **catégorie**, **urgence** et **SLA** via des règles
  configurables (critères : domaine de l'expéditeur, mots-clés du sujet/corps, destinataire…).
- **F4.2** Les règles sont gérées côté GLPI (idéalement réutilisant le moteur de règles natif) ;
  l'add-in délègue ce mapping au backend GLPI.
- **F4.3** Valeurs par défaut si aucune règle ne s'applique.

### F5 — Pièces jointes
- **F5.1** Les pièces jointes du mail sont **rattachées au ticket** (respect des types/poids autorisés par GLPI).
- **F5.2** Option : attacher **l'e-mail complet (`.eml`)** au ticket pour archivage.
- **F5.3** Filtrage configurable (images inline / signatures à exclure, taille max).

---

## 5. Exigences techniques

### 5.1 Brique A — Plugin GLPI (`glpi-dragndrop`)
- Plugin PHP standard (`setup.php`, `hook.php`, structure `plugins/dragndrop/`).
- Injection front via `$PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]` ; ancrage formulaire via
  `post_item_form` / `post_itil_info_section` (GLPI 11).
- Endpoint AJAX PHP : réception du fichier mail → parsing → JSON mappé.
- Parsing `.eml` : réutiliser le moteur MIME embarqué dans GLPI (`Laminas\Mail` /
  logique `MailCollector`). Parsing `.msg` : lib dédiée (ex. `hfig/mapi`), derrière une
  interface commune, dégradable.
- Pré-remplissage : remplissage des champs DOM (titre, description TinyMCE, demandeur,
  observateurs) + upload PJ via l'endpoint document GLPI. *(Alternative à évaluer : brouillon via API.)*

### 5.2 Brique B — Add-in Outlook (`glpi-outlook-addin`)
- Projet Office.js séparé ; **manifeste unifié JSON** (nouveau Outlook / OWA), fallback XML si besoin.
- Lecture du mail : `Office.context.mailbox.item` (from, subject, `body.getAsync`, `getAttachmentContentAsync`).
- Création : API REST GLPI (`initSession` app-token + user-token → `POST /Ticket` →
  `POST /Document` → liaisons demandeur/observateur).
- Stockage du user-token : *roaming settings* du complément ou écran de configuration.

### 5.3 Briques transverses
- Moteur de règles de mapping (centralisé côté GLPI).
- Assainissement HTML partagé.
- Compatibilité : matrice navigateurs (Chrome/Edge/Firefox) × clients Outlook (classique/nouveau/web).

---

## 6. Exigences non-fonctionnelles

- **Sécurité** : assainissement anti-XSS du corps des mails ; contrôle des uploads
  (types/poids, antivirus si dispo) ; protection de l'app-token ; CORS de l'API GLPI ;
  HTTPS obligatoire. *(Audit sécurité dédié avant mise en production.)*
- **Confidentialité / RGPD** : le contenu des mails transite par l'API → journalisation
  maîtrisée, pas de stockage superflu, information des utilisateurs.
- **Performance** : parsing d'un mail < 2 s en usage courant.
- **Robustesse** : gestion explicite des échecs de drag/parse avec message d'aide.
- **i18n** : interfaces en **FR** et **EN** au minimum.
- **Compatibilité** : GLPI 11.x ; navigateurs evergreen ; Outlook classique + nouveau + OWA.

---

## 7. Architecture & flux

### Flux Brique A (plugin GLPI)
```
Agent --(glisse .eml / mail)--> Dropzone (JS) --POST fichier--> Endpoint plugin (PHP)
   --> Parsing MIME --> JSON mappé --> JS pré-remplit le formulaire ticket --> Agent valide
```

### Flux Brique B (add-in Outlook)
```
Agent (Outlook) --clic/drag--> Volet add-in (Office.js)
   --lit mail (from/subject/body/PJ)--> Aperçu mapping --> Agent valide
   --> API REST GLPI: initSession -> POST /Ticket -> POST /Document -> liaisons -> Ticket créé
```

*(Diagrammes de séquence détaillés à produire en conception technique.)*

---

## 8. Contraintes & hypothèses techniques (limites documentées)

- **Nouveau Outlook / OWA** : Microsoft a retiré le drag de mails vers les apps web → le
  contenu n'est jamais transmis au navigateur. **L'add-in est la seule voie fiable.**
- **Outlook classique → Firefox** : `DataTransfer` vide (pas de support des fichiers virtuels Outlook).
- **Outlook classique → Chrome/Edge** : fichier `.msg` virtuel parfois exposé, `file.type`
  vide, format OLE binaire (parsing plus complexe que `.eml`).
- **Fichier `.eml`/`.msg` enregistré puis glissé** : fonctionne partout (fallback universel).

---

## 9. Phasage & livrables

| Phase | Contenu |
|---|---|
| **POC / Spikes** | (1) drag Outlook classique vs fichier `.eml` ; (2) add-in Office.js + `POST /Ticket` ; (3) pré-remplissage formulaire GLPI 11. |
| **MVP** | Add-in Outlook (3 clients) + mapping Titre/Description/Demandeur/PJ + auth jeton/utilisateur. |
| **V1** | Plugin GLPI dropzone `.eml` + pré-remplissage formulaire. |
| **V2** | Règles de mapping (entité/catégorie/SLA), observateurs Cc, support `.msg`, config avancée. |

---

## 10. Critères d'acceptation / recette (extraits)

- **F2.4** : un mail dont l'expéditeur n'a pas de compte GLPI crée bien un ticket avec
  demandeur rattaché par e-mail.
- **F3.1** : après dépôt, titre/description/demandeur sont pré-remplis correctement ; aucun
  ticket n'est créé tant que l'agent n'a pas validé.
- **F5.1** : les pièces jointes du mail sont présentes sur le ticket créé.
- **Brique B** : depuis le nouveau Outlook ET OWA, le clic « Créer un ticket GLPI » aboutit à
  un ticket conforme.

---

## 11. Risques

| # | Risque | Prob. | Impact | Mitigation |
|---|---|---|---|---|
| R1 | Pré-remplissage robuste du formulaire GLPI 11 (TinyMCE, demandeur ajax, upload PJ) | Élevée | Élevé | Spike dédié ; alternative création via API |
| R2 | Parsing `.msg` (OLE) peu fiable | Moyenne | Moyen | MVP `.eml` only ; `.msg` best-effort |
| R3 | Divergences API Office.js entre clients Outlook | Moyenne | Moyen | Matrice de compatibilité ; manifeste unifié |
| R4 | Sécurité (XSS, upload, token, CORS) | Moyenne | Élevé | Assainissement, audit sécurité avant prod |
| R5 | RGPD (contenu des mails via API) | Faible | Moyen | Journalisation maîtrisée, pas de stockage superflu |

---

*Document de cadrage — à raffiner après les spikes de faisabilité.*
