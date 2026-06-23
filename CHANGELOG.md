# Changelog

Toutes les modifications notables de ce projet sont documentées dans ce fichier.

Le format s'appuie sur [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/),
et ce projet suit le [Semantic Versioning](https://semver.org/lang/fr/).

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
