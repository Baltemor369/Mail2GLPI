# Changelog

Toutes les modifications notables de ce projet sont documentées dans ce fichier.

Le format s'appuie sur [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/),
et ce projet suit le [Semantic Versioning](https://semver.org/lang/fr/).

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
