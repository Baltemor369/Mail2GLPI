# Mail2GLPI — Add-in Outlook (Brique B)

Add-in Office.js qui ajoute un bouton **« Créer un ticket GLPI »** dans Outlook (classique,
nouveau, web) et convertit l'e-mail ouvert en ticket via l'**API REST GLPI**.

> 🚧 **Squelette / MVP en cours.** Le parcours « lire l'e-mail → aperçu → créer le ticket
> (titre + description) » est en place. Le rattachement du **demandeur par e-mail** et des
> **pièces jointes** est volontairement laissé en `TODO` (à valider lors du spike, car
> dépendant de la version exacte de l'API GLPI).

## Pourquoi un manifeste XML (et pas JSON unifié) ?

Le manifeste **XML (add-in only)** couvre **toutes** les plateformes Outlook : Windows
desktop (classique), nouveau Outlook, web et mobile. Le manifeste **JSON unifié** a des
limitations sur Outlook (modules, règles d'activation contextuelles) et exige Office ≥ 2304.
Comme la cible inclut Outlook classique, on retient le XML.

## Prérequis

- Node.js ≥ 18
- Un compte de boîte aux lettres Exchange / Microsoft 365 pour le sideload
- Une instance **GLPI** avec l'**API REST activée**, un **App-Token**, et un **User-Token** personnel

## Installation

```bash
cd addin
npm install
```

## Développement

```bash
npm run dev-server   # sert le volet en HTTPS sur https://localhost:3000
npm start            # build + sideload l'add-in dans Outlook (office-addin-debugging)
npm run validate     # valide le manifeste
```

Au premier lancement, `office-addin-dev-certs` installe un certificat de développement local
pour servir le volet en HTTPS (obligatoire pour Office.js).

## Configuration GLPI

Dans le volet, déplier **Paramètres GLPI** et renseigner :

- **URL de GLPI** — ex. `https://glpi.exemple.fr`
- **App-Token** — jeton d'application de l'API REST (configuré côté GLPI)
- **User-Token** — jeton personnel de l'agent (profil GLPI > Clés d'accès distantes)

Ces valeurs sont stockées dans les *roaming settings* Office (par utilisateur).

## Structure

```
addin/
  manifest.xml              Manifeste de l'add-in (XML)
  webpack.config.js         Build + dev-server HTTPS
  src/
    config.js               Lecture/écriture de la config GLPI (roaming settings)
    outlook/mailReader.js   Lecture de l'e-mail courant (Office.js)
    glpi/glpiClient.js       Client API REST GLPI (session, ticket, TODO: doc/acteurs)
    glpi/mailMapper.js       Mapping e-mail → ticket + assainissement HTML (DOMPurify)
    taskpane/               Volet (HTML/CSS/JS)
    commands/               FunctionFile (point d'extension)
  assets/                   Icônes (à fournir)
```

## Sécurité

- Le corps HTML de l'e-mail est **assaini avec DOMPurify** (liste blanche par défaut) avant
  affichage dans le volet et avant envoi à GLPI.
- L'URL GLPI est **forcée en HTTPS** (sauf `localhost` en dev) : les jetons transitent dans
  les en-têtes et ne doivent jamais circuler en clair.
- Le **User-Token** et l'**App-Token** sont des secrets : jamais codés en dur, jamais committés.
  Ils sont stockés dans les *roaming settings* Office (non chiffrés) → l'assainissement XSS est
  la première ligne de défense contre leur exfiltration. Une CSP restreinte est appliquée au volet.
- **Risque résiduel** (à arbitrer en V1) : des jetons longue durée côté client. Cible possible :
  un proxy backend détenant l'App-Token + un flux OAuth/SSO au lieu du User-Token personnel.
- Avant build de production : épingler `dompurify` à jour, committer le `package-lock.json` et
  exécuter `npm audit`.

## Reste à faire (spike & V1)

- [ ] Rattacher le demandeur/observateurs par e-mail (`GlpiClient.linkActorByEmail`).
- [ ] Téléverser les pièces jointes (`GlpiClient.addDocument`, endpoint Document multipart).
- [ ] Récupérer le contenu des PJ via `getAttachmentContentAsync` (Mailbox 1.8).
- [ ] Fournir les icônes PNG (`assets/`).
- [ ] Affecter entité/catégorie/urgence/SLA par règles (déléguer au backend GLPI).
