# Changelog

Toutes les modifications notables de ce projet sont documentées dans ce fichier.

Le format s'appuie sur [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/),
et ce projet suit le [Semantic Versioning](https://semver.org/lang/fr/).

## [0.8.0] - 2026-06-30

Jalon : **Suggestions IA validées en production** (catégorie, urgence, résumé via LLM local,
100 % privé). Cette version intègre les corrections issues de la revue de code et de l'audit de
sécurité du sous-système IA.

### Corrigé (revue de code)
- **AiClient** : la 2ᵉ tentative d'appel (sans `response_format`) n'a lieu **que** si le serveur a
  répondu (HTTP 200 non parsable, ou 400 format refusé) ; plus de double attente en cas
  d'injoignabilité/timeout (évitait un pire cas ~2×timeout saturant PHP-FPM).
- **AiClient** : `json_encode` sécurisé (`JSON_INVALID_UTF8_SUBSTITUTE` + garde) — un e-mail mal
  encodé n'envoie plus un corps vide silencieusement.
- **dropzone.js** : garde anti-concurrence (jeton de séquence) — deux dépôts rapprochés ne
  mélangent plus résumés/catégories dans la description (résultat périmé ignoré).
- **enrich.php** : urgence flottante entière (`3.0`) désormais acceptée ; correspondance de
  catégorie par **nom de feuille** pour les taxonomies hiérarchiques (`A > B > Feuille`) ;
  `ITILCategory::find()` trié (`completename`) → sous-ensemble déterministe sous plafond.
- **deploy.sh** : les erreurs d'installation/maj du plugin ne sont plus masquées.

### Sécurité (audit)
- Objet `_debug` réservé aux **admins** (`config/UPDATE`) — ne révèle plus l'URL interne du LLM à
  un agent standard quand le debug est actif.
- `ai_timeout` plafonné [5, 300] s côté serveur ; `set_time_limit` borné au timeout + marge (au
  lieu de `0`) — garde-fou anti-DoS.

### Refactor / Tests
- Helpers purs extraits dans `GlpiPlugin\Mail2glpi\AiText` (`normalize`, `parseUrgency`).
- Suite de tests sans framework `tests/AiTextTest.php` (`php tests/AiTextTest.php`) couvrant
  `parseUrgency`, `normalize` et `AiClient::extractJson`.

## [0.7.6] - 2026-06-30

### Ajouté (diagnostic)
- **Mode debug IA** (`ai_debug`, case dans la config) : la réponse de `enrich.php` inclut un objet
  `_debug` (config lue, `configured`, nb de catégories, `http_code`, erreur curl, contenu brut du
  modèle, JSON décodé). Permet d'identifier la cause exacte d'un échec IA en un seul test.
- **Self-test par URL** (admin + debug) : `ajax/enrich.php?selftest=1` exécute un exemple intégré
  et renvoie le `_debug` — diagnostic sans avoir à déposer un e-mail.
- `AiClient` expose `getLastHttpCode()`, `getLastError()`, `getLastRawContent()` (dernier appel).
- `enrich.php` : `set_time_limit(0)` pour ne pas couper l'inférence à froid ; traces `console.debug`
  côté `dropzone.js`.

## [0.7.5] - 2026-06-29

### Corrigé / Amélioré
- **Urgence IA** : le modèle renvoyait souvent un mot (« Faible », « Haute »…) → désormais
  converti en niveau GLPI 1-5 (chiffre direct ou mot FR/EN mappé). Prompt renforcé (chiffre).
- **Catégorie IA** : correspondance tolérante aux **accents et à la casse** contre les catégories
  ITIL existantes (ex. « Materiel » → « Matériel »).
- **`deploy.sh`** : réactive automatiquement le plugin après déploiement (`glpi:plugin:install`
  + `glpi:plugin:activate`), car GLPI le désactive à chaque changement de version. Best-effort
  (sinon réactivation via Configuration > Plugins).

## [0.7.4] - 2026-06-29

### Corrigé
- **« Action non autorisée » (403) après l'analyse IA.** Poser la catégorie via un `change`
  classique réveillait le handler GLPI qui recharge le gabarit de la catégorie en **resoumettant**
  le formulaire de ticket → soumission auto rejetée (403) et navigation vers la page d'erreur.
  La catégorie est désormais posée « en silence » (`change.select2`) : l'affichage est mis à jour
  sans déclencher de rechargement, et le champ reste valorisé pour la création du ticket.

## [0.7.3] - 2026-06-29

### Corrigé / Robustesse
- **Forçage JSON ré-introduit avec repli** : l'appel IA tente d'abord `response_format`
  (json_object) — fiable pour obtenir du JSON d'un petit modèle — puis réessaie **sans** ce
  paramètre si la version d'Ollama ne le supporte pas. Corrige le cas où le modèle 3B renvoyait
  du texte non-JSON (catégorie/urgence/résumé jamais extraits).

## [0.7.2] - 2026-06-29

### Corrigé / Robustesse
- **Retrait de `response_format`** dans l'appel au LLM : son support varie selon la version
  d'Ollama (risque de 400 qui faisait échouer l'enrichissement). Le JSON est obtenu via le
  prompt + un parseur tolérant (`extractJson`).
- **Diagnostic** : `enrich.php` journalise désormais quand l'appel IA ne renvoie rien (LLM
  injoignable / timeout / réponse invalide), au lieu d'échouer silencieusement.

## [0.7.1] - 2026-06-27

### Corrigé
- **Page de config : « action non autorisée » à l'enregistrement.** Le routeur GLPI 11 valide
  et consomme déjà le jeton CSRF des POST ; la page refaisait un `Session::checkCSRF` sur un jeton
  consommé → rejet. Suppression de la vérification manuelle (le routeur gère le CSRF, le champ
  caché reste pour qu'il valide). Même correctif que celui appliqué à `parse.php` en v0.3.6.

## [0.7.0] - 2026-06-27

### Ajouté
- **Suggestions IA locales** (catégorie, urgence, résumé) au dépôt d'un e-mail, via un **LLM
  local** (endpoint compatible OpenAI, ex. Ollama). **Aucune donnée n'est envoyée vers le cloud.**
  - Appel **asynchrone et best-effort** : le formulaire se pré-remplit immédiatement (titre,
    description, source, demandeur, PJ), puis catégorie/urgence/résumé se complètent quand le
    LLM répond. Si l'IA est désactivée/injoignable, le comportement de base est inchangé.
  - `src/AiClient.php` (client HTTP, **un seul appel** renvoyant un JSON), `public/ajax/enrich.php`
    (endpoint async ; valide la catégorie contre les catégories ITIL existantes ; borne les
    entrées), `public/front/config.form.php` (page de configuration : activation, URL, modèle,
    délai, clé optionnelle). Config stockée en base (`Config`).

### Sécurité (findings du pipeline qualité)
- Aucun secret renvoyé au client ; clé d'API **non réémise** dans le formulaire (champ vide,
  sauvegarde conditionnelle).
- Échappement de `$_SERVER['PHP_SELF']` (anti-XSS réfléchi) ; résumé IA inséré via texte échappé.
- URL de base restreinte à http(s) ; curl sans redirection et protocoles limités (anti-SSRF).
- Robustesse : garde `curl_init`, extraction JSON tolérante, décodage des entités HTML.

## [0.6.2] - 2026-06-25

### Documentation
- Ajout de **`docs/INSTALLATION.md`** (guide administrateur : prérequis, déploiement, activation,
  vérification, mise à jour, dépannage, désinstallation) et **`docs/UTILISATION.md`** (guide
  agent : obtenir le fichier mail, créer le ticket, champs pré-remplis, limites, dépannage).
- Mise à jour des README périmés : le README racine indiquait encore « code pas encore
  implémenté » ; le README du plugin présentait le plugin comme un squelette. Tous deux
  reflètent désormais l'état réel (Brique A fonctionnelle, Brique B en squelette).

## [0.6.1] - 2026-06-25

### Ajouté
- **`deploy.sh`** : script de déploiement du plugin sur la VM GLPI (Debian) — met à jour le
  dépôt git, copie le plugin, applique les droits au serveur web et vide le cache, en une
  commande (`bash deploy.sh`). Configurable via `GLPI_ROOT`, `WEB_USER`, `GIT_REF`, `PULL`.
  Garde-fous : vérifie les chemins et refuse un `rm -rf` hors de `…/plugins/mail2glpi`.
- `.gitattributes` : force les fins de ligne LF pour les scripts `.sh` (sinon bash échoue sous Linux).

## [0.6.0] - 2026-06-25

### Ajouté
- **Demandeur = expéditeur du mail** : l'expéditeur est positionné comme demandeur du ticket.
  - Côté serveur, l'e-mail est résolu en **compte GLPI** s'il existe (et actif/non supprimé) ;
    sinon **demandeur par e-mail** (`items_id=0` + `alternative_email`), comme le collecteur natif.
  - Côté client, le demandeur est injecté dans le composant « Acteurs » de GLPI 11
    (Select2 `data-actor-type="requester"` → champ caché `_actors`).
  - Vaut pour les deux formats (`.eml` et `.msg`).

### Note
- Les observateurs (Cc) ne sont pas encore rattachés — prochaine itération (même mécanisme).

## [0.5.2] - 2026-06-24

### Modifié
- La bibliothèque `.msg` (`DataStream.js`, `msg.reader.js`, Apache-2.0) est désormais
  **vendorisée (committée)** dans `plugin/public/js/vendor/`, avec sa `LICENSE`. Le déploiement
  « copier le dossier » fonctionne tel quel, hors-ligne, sans téléchargement séparé.

## [0.5.1] - 2026-06-24

### Corrigé
- **Images de signature rattachées par erreur** : les pièces jointes « inline » (logos/images
  de signature, référencées dans le corps via un Content-ID) ne sont plus ajoutées au ticket.
  Côté `.msg`, on ignore les PJ ayant un `pidContentId` ; côté `.eml`, celles marquées `inline`
  avec un `Content-ID`. Corrige le cas où la signature parasitait l'ajout de la vraie pièce jointe.

## [0.5.0] - 2026-06-24

### Ajouté
- **Prise en charge des fichiers `.msg` (Outlook classic)** : le `.msg` est lu **côté
  navigateur** par la bibliothèque `msg.reader` (Apache-2.0, à récupérer dans
  `public/js/vendor/` — cf. son README). Ses champs (sujet, corps, expéditeur) sont envoyés au
  serveur pour le mapping/source (mêmes règles que le `.eml`), et ses pièces jointes sont
  rattachées directement côté client. La dropzone accepte désormais `.eml` **ou** `.msg`.
- `parse.php` : nouveau mode `msg` (mapping de champs pré-extraits), en plus du mode `.eml`.

### Sécurité / robustesse (findings du pipeline qualité)
- Borne de taille sur les champs du mode `msg` + validation stricte du paramètre `mode`.
- Garde sur le contenu des pièces jointes `.msg` (pas de fichier 0 octet), garde
  `file.arrayBuffer` (navigateurs anciens), et statut d'avertissement si les pièces jointes
  ne peuvent pas être ajoutées (éditeur non prêt) au lieu d'un faux « succès ».

### Note
- La bibliothèque tierce `.msg` n'est volontairement pas committée : à récupérer une fois
  (cf. `plugin/public/js/vendor/README.md`). Sans elle, le `.eml` fonctionne normalement.

## [0.4.2] - 2026-06-24

### Corrigé
- **Rattachement des pièces jointes** : `uploadFile()` (GLPI 11) exige l'éditeur TinyMCE en 2ᵉ
  argument (il appelle `editor.getElement()` pour retrouver l'uploader). On lui passe désormais
  l'éditeur de la description. Corrige l'erreur `Cannot read properties of undefined (reading
  'getElement')` qui empêchait l'import du fichier.

## [0.4.1] - 2026-06-24

### Corrigé
- Synchronisation de la constante `PLUGIN_MAIL2GLPI_VERSION` (`setup.php`) avec la version du
  projet : elle était restée figée à `0.3.0` depuis l'init, ce qui rendait le numéro de version
  affiché par GLPI trompeur (sans impact fonctionnel). Désormais alignée sur `VERSION`.

## [0.4.0] - 2026-06-24

### Ajouté
- **Source de la demande = « E-Mail »** : pré-positionnée automatiquement à partir de
  `RequestType::getDefault('mail')` (comme le collecteur natif).
- **Pièces jointes** : le contenu des PJ de l'e-mail est extrait (base64) puis ajouté à
  l'uploader du formulaire via la fonction GLPI `uploadFile()` (rattachement à la soumission).
  Plafonds : 5 Mo par pièce, 10 Mo cumulés ; au-delà la pièce est ignorée et signalée à l'agent.

### Sécurité / robustesse (findings du pipeline qualité)
- Borne HAUTE sûre de la taille décodée (corrige une sous-estimation en quoted-printable qui
  contournait le plafond) ; décodage strict (un base64 invalide est ignoré, pas envoyé corrompu).
- Budget cumulé sur le poids des PJ renvoyées (anti-amplification mémoire/réponse).
- Compteur d'aperçu distinguant les PJ **ignorées** (trop volumineuses) des **échecs** d'ajout ;
  trace `console.warn` en cas d'échec d'ajout.

## [0.3.6] - 2026-06-24

### Corrigé
- **403 « Jeton de sécurité invalide » au dépôt** : suppression de la validation CSRF manuelle
  dans `parse.php`. En GLPI 11, le routeur valide déjà le CSRF des requêtes AJAX via l'en-tête
  `X-Glpi-Csrf-Token` (envoyé par `dropzone.js`) **et consomme le jeton** ; la re-validation par
  `Session::validateCSRF` testait alors un jeton déjà absent → 403 systématique. L'endpoint
  reste protégé par le routeur (mécanisme CSRF officiel de GLPI 11).

## [0.3.5] - 2026-06-24

### Corrigé
- `parse.php` : `Session::validateCSRF(..., true)` (`preserve_token`) pour ne pas consommer le
  jeton AJAX réutilisable de GLPI 11 — sinon un second dépôt de `.eml` dans la même page
  échouait. Issu de l'audit sécurité du correctif CSRF (finding robustesse).

## [0.3.4] - 2026-06-24

### Corrigé
- **CSRF AJAX GLPI 11** : le jeton est désormais transmis via l'en-tête `X-Glpi-Csrf-Token`
  (jeton réutilisable `getAjaxCsrfToken()`), avec `X-Requested-With: XMLHttpRequest`. Côté
  serveur, `parse.php` valide via `Session::validateCSRF` (lit l'en-tête ou le champ de repli)
  et renvoie une erreur **JSON** au lieu d'une page HTML. Corrige l'erreur
  « Unexpected token '<' … is not valid JSON » au dépôt d'un fichier.
- `dropzone.js` : lecture de la réponse en texte puis parsing tolérant (message lisible si la
  réponse n'est pas du JSON, ex. session expirée).

## [0.3.3] - 2026-06-24

### Corrigé
- **Compatibilité GLPI 11** : déplacement des assets et de l'endpoint AJAX sous `public/`
  (`public/js`, `public/css`, `public/ajax`). Sans cela, GLPI 11 renvoyait 404 sur
  `dropzone.js`/`dropzone.css` (les fichiers statiques doivent être sous `public/`).
- `parse.php` : suppression de `include('inc/includes.php')` — le routeur GLPI 11 initialise
  automatiquement l'environnement et applique le pare-feu (authentifié par défaut).

## [0.3.2] - 2026-06-24

### Corrigé
- `dropzone.js` : passage en **délégation d'événements** (capture sur `document`). La dropzone
  réagit désormais même lorsque le formulaire GLPI 11 est rendu après le chargement du script,
  et le drop est intercepté avant l'uploader natif de GLPI (`stopPropagation`).

## [0.3.1] - 2026-06-23

### Ajouté
- `examples/sample.eml` : e-mail de test (sujet accentué encodé, multipart mixed/alternative
  imbriqué, corps texte + HTML, pièce jointe base64) pour valider le parsing de la Brique A
  sans dépendre d'un client mail.

## [0.3.0] - 2026-06-23

### Ajouté
- **Brique A — Squelette du plugin GLPI 11** (clé `mail2glpi`) dans `plugin/` : dropzone
  injectée dans le formulaire de création de ticket (hook `post_itil_info_section`), endpoint
  AJAX d'analyse, parsing MIME du `.eml` via laminas/laminas-mail (fourni par GLPI), mapping
  e-mail → ticket et pré-remplissage du titre et de la description.
- `setup.php`/`hook.php` (init, hooks, prérequis), `composer.json` (autoload PSR-4
  `GlpiPlugin\Mail2glpi`), assets JS/CSS, dossier `locales/`.

### Sécurité
- Endpoint AJAX protégé : authentification, droit `ticket/CREATE`, jeton CSRF, validation
  stricte de l'upload (.eml, taille réelle sur disque, `is_uploaded_file`).
- Description pré-remplie en **texte échappé** (pas d'injection de HTML non fiable).
- Garde-fous anti-DoS dans le parsing (profondeur et nombre de parties bornés, taille des
  pièces jointes estimée sans décodage).

### Notes
- Rattachement du demandeur/observateurs par e-mail, upload des pièces jointes, règles de
  mapping et support `.msg` laissés en `TODO` (V1/V2).

## [0.2.0] - 2026-06-23

### Ajouté
- **Brique B — Squelette de l'add-in Outlook (Office.js)** dans `addin/` : manifeste XML
  (couvre Outlook classique, nouveau et web), volet d'aperçu, lecture de l'e-mail courant
  (expéditeur, sujet, corps, pièces jointes) et création de ticket via l'API REST GLPI
  (initSession / Ticket). Configuration GLPI (URL, App-Token, User-Token) via roaming settings.
- Mapping e-mail → ticket avec assainissement du corps HTML (DOMPurify).

### Sécurité
- URL GLPI forcée en HTTPS (sauf `localhost`) pour protéger les jetons.
- DOMPurify configuré sur sa liste blanche par défaut (pas de liste noire partielle).
- CSP restreinte sur le volet ; `dompurify` épinglé en version récente.

### Notes
- Rattachement du demandeur par e-mail et envoi des pièces jointes laissés en `TODO`
  (à finaliser lors du spike, dépend de la version de l'API GLPI).

## [0.1.0] - 2026-06-23

### Ajouté
- Étude de faisabilité du projet (plugin GLPI + add-in Outlook pour convertir un e-mail en ticket).
- Cahier des Charges (`CDC.md`) : périmètre, exigences fonctionnelles/techniques, architecture,
  contraintes Outlook/navigateur, phasage (POC → MVP → V1 → V2), risques.
- Initialisation du versioning (`VERSION`, `CHANGELOG.md`).
- `README.md` présentant le projet, l'architecture en deux briques et la feuille de route.
